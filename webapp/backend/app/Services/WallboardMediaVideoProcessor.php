<?php

namespace App\Services;

use App\Support\WallboardMediaProcessedAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class WallboardMediaVideoProcessor
{
    /** @var list<string> */
    private const ALLOWED_BRANDS = ['avc1', 'iso2', 'iso4', 'iso5', 'iso6', 'isom', 'mp41', 'mp42'];

    public function process(UploadedFile $upload, string $field = 'file'): WallboardMediaProcessedAsset
    {
        $sourcePath = $upload->getRealPath();
        $sourceBytes = $upload->getSize();
        $maxBytes = max(1, (int) config('wallboard_media.max_video_upload_kilobytes', 250 * 1024)) * 1024;
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

        $durationSeconds = $this->validateContainer($sourcePath, $sourceBytes, $field);
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
                width: null,
                height: null,
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

    private function validateContainer(string $path, int $fileSize, string $field): int
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
                    if ($ftypFound || $box['payload_size'] < 8 || $box['payload_size'] > 256) {
                        $this->invalid($field, 'De MP4-container heeft ongeldige typegegevens.');
                    }
                    $ftypFound = true;
                    fseek($stream, $box['payload_offset']);
                    $payload = fread($stream, $box['payload_size']);
                    if (! is_string($payload) || strlen($payload) !== $box['payload_size']) {
                        $this->invalid($field, 'De MP4-typegegevens konden niet worden gelezen.');
                    }
                    for ($brandOffset = 0; $brandOffset + 4 <= strlen($payload); $brandOffset += 4) {
                        if (in_array(substr($payload, $brandOffset, 4), self::ALLOWED_BRANDS, true)) {
                            $allowedBrandFound = true;
                            break;
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

            if (! $ftypFound || ! $allowedBrandFound || $moovOffset === null || $mdatOffset === null
                || $moovStart === null || $moovEnd === null || $moovOffset > $mdatOffset) {
                $this->invalid(
                    $field,
                    'De MP4 moet browsergeschikt zijn en de afspeelindex vóór de videodata bevatten.',
                );
            }
            $duration = $this->movieDuration($stream, $moovStart, $moovEnd);
            $maximum = max(1, (int) config('wallboard_media.max_video_duration_seconds', 6 * 60 * 60));
            if ($duration < 1 || $duration > $maximum) {
                $this->invalid($field, 'De videoduur valt buiten de toegestane grenzen.');
            }

            return $duration;
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

    /** @return never */
    private function invalid(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
