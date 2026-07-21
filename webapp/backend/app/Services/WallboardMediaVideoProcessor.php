<?php

namespace App\Services;

use App\Support\SensitiveDataRedactor;
use App\Support\WallboardMediaProcessedAsset;
use App\Support\WallboardMediaVideoProgressParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class WallboardMediaVideoProcessor
{
    private const MAX_RAW_FFMPEG_DIAGNOSTIC_BYTES = 16_384;

    private const MAX_FFMPEG_DIAGNOSTIC_BYTES = 2_048;

    /** @var list<string> */
    private const ALLOWED_BRANDS = ['avc1', 'iso2', 'iso4', 'iso5', 'iso6', 'isom', 'mp41', 'mp42'];

    private const BROWSER_VIDEO_CODEC = 'h264';

    private const BROWSER_AUDIO_CODEC = 'aac';

    /** @var list<string> */
    private const BROWSER_PIXEL_FORMATS = ['yuv420p', 'yuvj420p'];

    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    public function process(UploadedFile $upload, string $field = 'file'): WallboardMediaProcessedAsset
    {
        $sourcePath = $upload->getRealPath();
        $sourceBytes = $upload->getSize();
        $maxBytes = max(1, (int) config('wallboard_media.max_video_upload_kilobytes', 512 * 1024)) * 1024;
        if (! $upload->isValid()
            || ! is_string($sourcePath)
            || ! is_file($sourcePath)
            || ! is_int($sourceBytes)
            || $sourceBytes < 32
            || $sourceBytes > $maxBytes
            || strtolower($upload->getClientOriginalExtension()) !== 'mp4') {
            $this->invalid($field, 'Upload een geldig MP4-bestand binnen de toegestane bestandsgrootte.');
        }

        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        } catch (Throwable) {
            $mime = null;
        }
        if ($mime !== 'video/mp4') {
            $this->invalid($field, 'Alleen een daadwerkelijk MP4-videobestand is toegestaan.');
        }

        $container = $this->validateContainer($sourcePath, $sourceBytes, $field);
        $durationSeconds = $container['duration_seconds'];
        $metadata = $this->inspectVideo($sourcePath, $field);
        if (abs($metadata['duration_seconds'] - $durationSeconds) > 2) {
            $this->invalid($field, 'De videoduur in de MP4-container is niet consistent.');
        }
        $requiresTranscode = ! $container['browser_streamable'] || $this->requiresTranscode($metadata);
        $temporaryPath = $this->temporaryPath();
        try {
            $source = @fopen($sourcePath, 'rb');
            $destination = @fopen($temporaryPath, 'wb');
            if (! is_resource($source) || ! is_resource($destination)) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($destination)) {
                    fclose($destination);
                }
                throw new HttpException(503, 'De video kon niet veilig worden gekopieerd.');
            }
            try {
                $copied = stream_copy_to_stream($source, $destination, $sourceBytes + 1);
                if ($copied !== $sourceBytes || ! fflush($destination)) {
                    throw new HttpException(503, 'De video kon niet volledig worden gekopieerd.');
                }
            } finally {
                fclose($source);
                fclose($destination);
            }
            @chmod($temporaryPath, 0640);
            $byteSize = @filesize($temporaryPath);
            $sha256 = @hash_file('sha256', $temporaryPath);
            if ($byteSize !== $sourceBytes
                || ! is_string($sha256)
                || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new HttpException(503, 'De opgeslagen video kon niet worden geverifieerd.');
            }

            return new WallboardMediaProcessedAsset(
                kind: 'video',
                temporaryPath: $temporaryPath,
                sha256: $sha256,
                mimeType: 'video/mp4',
                byteSize: $byteSize,
                width: $metadata['width'],
                height: $metadata['height'],
                durationSeconds: $durationSeconds,
                thumbnailTemporaryPath: null,
                thumbnailSha256: null,
                thumbnailByteSize: null,
                requiresVideoTranscode: $requiresTranscode,
            );
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw $exception;
        }
    }

    /** @param null|callable(int): void $progress */
    public function transcode(
        string $sourcePath,
        ?int $durationSeconds = null,
        ?callable $progress = null,
    ): WallboardMediaProcessedAsset {
        $sourceBytes = @filesize($sourcePath);
        if (! is_int($sourceBytes) || $sourceBytes < 32) {
            throw new HttpException(503, 'De opgeslagen video kon niet veilig worden gelezen.');
        }
        $sourceSha256 = @hash_file('sha256', $sourcePath);
        if (! is_string($sourceSha256) || preg_match('/^[a-f0-9]{64}$/', $sourceSha256) !== 1) {
            throw new HttpException(503, 'De opgeslagen video kon niet veilig worden geverifieerd.');
        }

        $temporaryPath = $this->temporaryPath();
        try {
            $maximumWidth = max(2, (int) config('wallboard_media.max_video_width_pixels', 1920));
            $maximumHeight = max(2, (int) config('wallboard_media.max_video_height_pixels', 1080));
            $progressParser = $progress === null
                ? null
                : new WallboardMediaVideoProgressParser(max(1, (int) $durationSeconds));
            $outputHandler = $progressParser === null
                ? null
                : static function (string $type, string $output) use ($progressParser, $progress): void {
                    if ($type !== 'out') {
                        return;
                    }
                    foreach ($progressParser->consume($output) as $percentage) {
                        $progress($percentage);
                    }
                };
            $process = Process::timeout(max(
                120,
                (int) config('wallboard_media.video_transcode_timeout_seconds', 3600),
            ))->start([
                (string) config('wallboard_media.ffmpeg_binary', '/usr/bin/ffmpeg'),
                '-nostdin',
                '-hide_banner',
                '-loglevel',
                'error',
                '-stats_period',
                '1',
                '-progress',
                'pipe:1',
                '-nostats',
                '-i',
                $sourcePath,
                '-map',
                '0:v:0',
                '-map',
                '0:a?',
                '-sn',
                '-dn',
                '-vf',
                "scale=w='min({$maximumWidth},iw)':h='min({$maximumHeight},ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
                '-c:v',
                'libx264',
                '-preset',
                'medium',
                '-crf',
                '23',
                '-pix_fmt',
                'yuv420p',
                '-c:a',
                'aac',
                '-b:a',
                '160k',
                '-map_metadata',
                '-1',
                '-movflags',
                '+faststart',
                '-f',
                'mp4',
                '-y',
                $temporaryPath,
            ], $outputHandler);
            $result = $process->wait();
            if (! $result->successful()) {
                Log::warning('Wallboard video transcoding process failed.', [
                    'exit_code' => (int) $result->exitCode(),
                    'diagnostic' => $this->sanitizeFfmpegDiagnostic(
                        $result->errorOutput(),
                        [$sourcePath, $temporaryPath],
                    ),
                ]);
                throw new HttpException(503, 'De video kon niet veilig naar 1080p worden verwerkt.');
            }

            $byteSize = @filesize($temporaryPath);
            if (! is_int($byteSize) || $byteSize < 32) {
                throw new HttpException(503, 'De verwerkte video is niet volledig opgeslagen.');
            }
            try {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
            } catch (Throwable) {
                $mime = null;
            }
            if ($mime !== 'video/mp4') {
                throw new HttpException(503, 'De verwerkte video heeft geen geldig MP4-formaat.');
            }
            $container = $this->validateContainer($temporaryPath, $byteSize, 'file');
            $durationSeconds = $container['duration_seconds'];
            $metadata = $this->inspectVideo($temporaryPath, 'file');
            if (! $container['browser_streamable']
                || $metadata['width'] > $maximumWidth
                || $metadata['height'] > $maximumHeight
                || $metadata['codec_name'] !== self::BROWSER_VIDEO_CODEC
                || ! in_array($metadata['pixel_format'], self::BROWSER_PIXEL_FORMATS, true)
                || collect($metadata['audio_codecs'])->contains(
                    static fn (string $codec): bool => $codec !== self::BROWSER_AUDIO_CODEC,
                )
                || abs($metadata['duration_seconds'] - $durationSeconds) > 2) {
                throw new HttpException(503, 'De verwerkte video voldoet niet aan het veilige 1080p-profiel.');
            }
            $sha256 = @hash_file('sha256', $temporaryPath);
            if (! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new HttpException(503, 'De verwerkte video kon niet worden geverifieerd.');
            }
            @chmod($temporaryPath, 0640);
            if ($progress !== null) {
                $progress(99);
            }

            return new WallboardMediaProcessedAsset(
                kind: 'video',
                temporaryPath: $temporaryPath,
                sha256: $sha256,
                mimeType: 'video/mp4',
                byteSize: $byteSize,
                width: $metadata['width'],
                height: $metadata['height'],
                durationSeconds: $durationSeconds,
                thumbnailTemporaryPath: null,
                thumbnailSha256: null,
                thumbnailByteSize: null,
            );
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw $exception;
        }
    }

    /** @return array{width: int, height: int, duration_seconds: int, codec_name: string, pixel_format: string, audio_codecs: list<string>} */
    private function inspectVideo(string $path, string $field): array
    {
        $result = Process::timeout(max(
            10,
            (int) config('wallboard_media.video_probe_timeout_seconds', 30),
        ))->run([
            (string) config('wallboard_media.ffprobe_binary', '/usr/bin/ffprobe'),
            '-v',
            'error',
            '-show_entries',
            'stream=codec_type,codec_name,pix_fmt,width,height:format=duration,format_name',
            '-of',
            'json',
            $path,
        ]);
        $output = $result->output();
        if (! $result->successful() || strlen($output) > 65_536) {
            $this->invalid($field, 'De MP4-videostream kon niet veilig worden gecontroleerd.');
        }
        try {
            $decoded = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }
        $streams = is_array($decoded['streams'] ?? null) ? array_values($decoded['streams']) : null;
        if ($streams === null || $streams === [] || count($streams) > 32) {
            $this->invalid($field, 'De MP4-videostream bevat ongeldige metadata.');
        }
        $stream = null;
        $audioCodecs = [];
        foreach ($streams as $candidate) {
            if (! is_array($candidate)) {
                $this->invalid($field, 'De MP4-videostream bevat ongeldige metadata.');
            }
            $codecType = strtolower(trim((string) ($candidate['codec_type'] ?? '')));
            if ($codecType === 'video' && $stream === null) {
                $stream = $candidate;
            }
            if ($codecType === 'audio') {
                $audioCodec = strtolower(trim((string) ($candidate['codec_name'] ?? '')));
                if (preg_match('/^[a-z0-9_]{2,32}$/D', $audioCodec) !== 1) {
                    $this->invalid($field, 'De MP4-audiostream bevat ongeldige metadata.');
                }
                $audioCodecs[] = $audioCodec;
            }
        }
        $format = is_array($decoded['format'] ?? null) ? $decoded['format'] : null;
        $width = filter_var($stream['width'] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($stream['height'] ?? null, FILTER_VALIDATE_INT);
        $duration = filter_var($format['duration'] ?? null, FILTER_VALIDATE_FLOAT);
        $formatNames = array_filter(explode(',', strtolower(trim((string) ($format['format_name'] ?? '')))));
        $codecName = strtolower(trim((string) ($stream['codec_name'] ?? '')));
        $pixelFormat = strtolower(trim((string) ($stream['pix_fmt'] ?? '')));
        if (! is_int($width) || $width < 2 || $width > 16_384
            || ! is_int($height) || $height < 2 || $height > 16_384
            || ! is_float($duration) || ! is_finite($duration) || $duration < 0.1
            || ! in_array('mp4', $formatNames, true)
            || preg_match('/^[a-z0-9_]{2,32}$/D', $codecName) !== 1
            || preg_match('/^[a-z0-9_]{2,32}$/D', $pixelFormat) !== 1) {
            $this->invalid($field, 'De MP4-videostream bevat ongeldige metadata.');
        }

        return [
            'width' => $width,
            'height' => $height,
            'duration_seconds' => (int) ceil($duration),
            'codec_name' => $codecName,
            'pixel_format' => $pixelFormat,
            'audio_codecs' => $audioCodecs,
        ];
    }

    /** @param array{width: int, height: int, duration_seconds: int, codec_name: string, pixel_format: string, audio_codecs: list<string>} $metadata */
    private function requiresTranscode(array $metadata): bool
    {
        return $metadata['width'] > max(2, (int) config('wallboard_media.max_video_width_pixels', 1920))
            || $metadata['height'] > max(2, (int) config('wallboard_media.max_video_height_pixels', 1080))
            || $metadata['codec_name'] !== self::BROWSER_VIDEO_CODEC
            || ! in_array($metadata['pixel_format'], self::BROWSER_PIXEL_FORMATS, true)
            || collect($metadata['audio_codecs'])->contains(
                static fn (string $codec): bool => $codec !== self::BROWSER_AUDIO_CODEC,
            );
    }

    /** @return array{duration_seconds: int, browser_streamable: bool} */
    private function validateContainer(string $path, int $fileSize, string $field): array
    {
        $stream = @fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new HttpException(503, 'De video kon niet veilig worden gelezen.');
        }
        try {
            $offset = 0;
            $ftypFound = false;
            $allowedBrandFound = false;
            $moovOffset = null;
            $moovStart = null;
            $moovEnd = null;
            $mdatOffset = null;
            while ($offset < $fileSize) {
                $box = $this->boxAt($stream, $offset, $fileSize);
                if ($box === null) {
                    $this->invalid($field, 'De MP4-container is beschadigd.');
                }
                if ($box['type'] === 'ftyp') {
                    if ($ftypFound
                        || $box['payload_size'] < 8
                        || $box['payload_size'] > 256
                        || $box['payload_size'] % 4 !== 0) {
                        $this->invalid($field, 'De MP4-container heeft ongeldige typegegevens.');
                    }
                    $ftypFound = true;
                    fseek($stream, $box['payload_offset']);
                    $payload = fread($stream, $box['payload_size']);
                    if (! is_string($payload) || strlen($payload) !== $box['payload_size']) {
                        $this->invalid($field, 'De MP4-typegegevens konden niet worden gelezen.');
                    }
                    $allowedBrandFound = in_array(substr($payload, 0, 4), self::ALLOWED_BRANDS, true);
                    for ($brandOffset = 8;
                        ! $allowedBrandFound && $brandOffset + 4 <= strlen($payload);
                        $brandOffset += 4) {
                        if (in_array(substr($payload, $brandOffset, 4), self::ALLOWED_BRANDS, true)) {
                            $allowedBrandFound = true;
                        }
                    }
                } elseif ($box['type'] === 'moov') {
                    $moovOffset ??= $offset;
                    $moovStart ??= $box['payload_offset'];
                    $moovEnd ??= $box['end'];
                } elseif ($box['type'] === 'mdat') {
                    $mdatOffset ??= $offset;
                }
                $offset = $box['end'];
            }

            if (! $ftypFound || $moovOffset === null || $mdatOffset === null
                || $moovStart === null || $moovEnd === null) {
                $this->invalid($field, 'De MP4-container mist geldige type-, index- of videodata.');
            }
            $duration = $this->movieDuration($stream, $moovStart, $moovEnd);
            $maximum = max(1, (int) config('wallboard_media.max_video_duration_seconds', 6 * 60 * 60));
            if ($duration < 1 || $duration > $maximum) {
                $this->invalid($field, 'De videoduur valt buiten de toegestane grenzen.');
            }

            return [
                'duration_seconds' => $duration,
                'browser_streamable' => $allowedBrandFound && $moovOffset < $mdatOffset,
            ];
        } finally {
            fclose($stream);
        }
    }

    private function movieDuration(mixed $stream, int $start, int $end): int
    {
        $offset = $start;
        while ($offset < $end) {
            $box = $this->boxAt($stream, $offset, $end);
            if ($box === null) {
                return 0;
            }
            if ($box['type'] === 'mvhd') {
                fseek($stream, $box['payload_offset']);
                $versionBytes = fread($stream, min($box['payload_size'], 32));
                if (! is_string($versionBytes) || strlen($versionBytes) < 20) {
                    return 0;
                }
                $version = ord($versionBytes[0]);
                if ($version === 0 && strlen($versionBytes) >= 20) {
                    $timescale = $this->uint32(substr($versionBytes, 12, 4));
                    $duration = $this->uint32(substr($versionBytes, 16, 4));
                } elseif ($version === 1 && strlen($versionBytes) >= 32) {
                    $timescale = $this->uint32(substr($versionBytes, 20, 4));
                    $high = $this->uint32(substr($versionBytes, 24, 4));
                    $low = $this->uint32(substr($versionBytes, 28, 4));
                    if ($high > 0) {
                        return 0;
                    }
                    $duration = $low;
                } else {
                    return 0;
                }

                return $timescale > 0 && $duration > 0 ? (int) ceil($duration / $timescale) : 0;
            }
            $offset = $box['end'];
        }

        return 0;
    }

    /** @return array{type: string, payload_offset: int, payload_size: int, end: int}|null */
    private function boxAt(mixed $stream, int $offset, int $boundary): ?array
    {
        if ($offset < 0 || $boundary - $offset < 8 || fseek($stream, $offset) !== 0) {
            return null;
        }
        $header = fread($stream, 8);
        if (! is_string($header) || strlen($header) !== 8) {
            return null;
        }
        $size = $this->uint32(substr($header, 0, 4));
        $type = substr($header, 4, 4);
        $headerSize = 8;
        if (preg_match('/^[A-Za-z0-9 ]{4}$/D', $type) !== 1) {
            return null;
        }
        if ($size === 1) {
            $extended = fread($stream, 8);
            if (! is_string($extended) || strlen($extended) !== 8 || $this->uint32(substr($extended, 0, 4)) !== 0) {
                return null;
            }
            $size = $this->uint32(substr($extended, 4, 4));
            $headerSize = 16;
        } elseif ($size === 0) {
            $size = $boundary - $offset;
        }
        if ($size < $headerSize || $size > $boundary - $offset) {
            return null;
        }

        return [
            'type' => $type,
            'payload_offset' => $offset + $headerSize,
            'payload_size' => $size - $headerSize,
            'end' => $offset + $size,
        ];
    }

    private function uint32(string $bytes): int
    {
        $value = unpack('Nvalue', $bytes);

        return is_array($value) ? (int) ($value['value'] ?? 0) : 0;
    }

    private function temporaryPath(): string
    {
        $disk = Storage::disk((string) config('wallboard_media.disk', 'local'));
        $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');
        $directory = $disk->path($root.'/staging');
        if ((! is_dir($directory) && ! @mkdir($directory, 0770, true)) || ! is_writable($directory)) {
            throw new HttpException(503, 'De tijdelijke mediaopslag is niet beschikbaar.');
        }
        @chmod($directory, 0770);
        $path = tempnam($directory, 'upload-');
        if (! is_string($path)) {
            throw new HttpException(503, 'De tijdelijke mediaopslag is niet beschikbaar.');
        }

        return $path;
    }

    /** @param list<string> $mediaPaths */
    private function sanitizeFfmpegDiagnostic(string $diagnostic, array $mediaPaths): string
    {
        $diagnostic = substr($diagnostic, 0, self::MAX_RAW_FFMPEG_DIAGNOSTIC_BYTES);
        $diagnostic = mb_convert_encoding($diagnostic, 'UTF-8', 'UTF-8');
        $pathVariants = [];
        foreach ($mediaPaths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            $pathVariants[] = $path;
            $pathVariants[] = str_replace('\\', '/', $path);
            $pathVariants[] = str_replace('/', '\\', $path);
            $pathVariants[] = dirname($path);
            $pathVariants[] = basename($path);
        }
        $pathVariants = array_values(array_unique(array_filter(
            $pathVariants,
            static fn (string $path): bool => $path !== '' && $path !== '.',
        )));
        usort($pathVariants, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        if ($pathVariants !== []) {
            $diagnostic = str_replace($pathVariants, '[MEDIA_PATH]', $diagnostic);
        }

        $diagnostic = $this->redactor->redactString($diagnostic);
        $diagnostic = preg_replace([
            '~(?<![A-Za-z0-9])[A-Za-z]:[\\\\/][^\s"\'<>|]+~u',
            '~(?<![A-Za-z0-9:])/(?:[^/\s"\']+/)*[^/\s"\':;,]+~u',
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
        ], ['[PATH]', '[PATH]', ' '], $diagnostic) ?? '';
        $diagnostic = preg_replace('/\s+/u', ' ', trim($diagnostic)) ?? '';
        if ($diagnostic === '') {
            return 'Geen FFmpeg-diagnostiek ontvangen.';
        }
        if (strlen($diagnostic) <= self::MAX_FFMPEG_DIAGNOSTIC_BYTES) {
            return $diagnostic;
        }

        return mb_strcut(
            $diagnostic,
            0,
            self::MAX_FFMPEG_DIAGNOSTIC_BYTES - 3,
            'UTF-8',
        ).'...';
    }

    /** @return never */
    private function invalid(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
