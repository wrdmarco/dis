<?php

namespace App\Services;

use App\Support\WallboardMediaProcessedAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class WallboardMediaImageProcessor
{
    /** @var array<string, int> */
    private const ALLOWED_TYPES = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    public function process(UploadedFile $upload, string $field = 'image'): WallboardMediaProcessedAsset
    {
        $sourcePath = $upload->getRealPath();
        $sourceBytes = $upload->getSize();
        $maxBytes = max(1, (int) config('wallboard_media.max_upload_kilobytes', 15 * 1024)) * 1024;
        if (! $upload->isValid()
            || ! is_string($sourcePath)
            || ! is_file($sourcePath)
            || ! is_int($sourceBytes)
            || $sourceBytes < 1
            || $sourceBytes > $maxBytes) {
            $this->invalid($field, 'Upload een geldige afbeelding binnen de toegestane bestandsgrootte.');
        }

        $detectedMime = $this->detectedMime($sourcePath);
        $dimensions = @getimagesize($sourcePath);
        if (! isset(self::ALLOWED_TYPES[$detectedMime])
            || ! is_array($dimensions)
            || (int) ($dimensions[2] ?? 0) !== self::ALLOWED_TYPES[$detectedMime]
            || strtolower((string) ($dimensions['mime'] ?? '')) !== $detectedMime) {
            $this->invalid($field, 'Alleen geldige JPG-, PNG- en WebP-afbeeldingen zijn toegestaan.');
        }

        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        $maxPixels = max(1, (int) config('wallboard_media.max_source_pixels', 16_000_000));
        if ($width < 1 || $height < 1 || $width > intdiv($maxPixels, $height)) {
            $this->invalid($field, 'De afbeelding heeft te veel pixels om veilig te verwerken.');
        }

        $source = @file_get_contents($sourcePath);
        if (! is_string($source) || strlen($source) !== $sourceBytes) {
            throw new HttpException(503, 'De afbeelding kon niet veilig worden gelezen.');
        }
        if (($detectedMime === 'image/webp' && str_contains($source, 'ANIM'))
            || ($detectedMime === 'image/png' && str_contains($source, 'acTL'))) {
            $this->invalid($field, 'Geanimeerde afbeeldingen zijn niet toegestaan.');
        }

        $this->assertGdAvailable();
        $image = @imagecreatefromstring($source);
        if (! $image instanceof \GdImage) {
            $this->invalid($field, 'De afbeelding is beschadigd of kan niet veilig worden gedecodeerd.');
        }

        $temporaryPath = null;
        $thumbnailTemporaryPath = null;
        try {
            $image = $this->applyOrientation($image, $sourcePath, $detectedMime);
            $image = $this->resize($image);
            $temporaryPath = $this->temporaryPath();
            $quality = min(max((int) config('wallboard_media.webp_quality', 88), 60), 95);
            if (! @imagewebp($image, $temporaryPath, $quality)) {
                throw new HttpException(503, 'De afbeelding kon niet veilig worden gecodeerd.');
            }
            @chmod($temporaryPath, 0640);
            $thumbnailTemporaryPath = $this->encodeThumbnail($image);

            $outputMime = $this->detectedMime($temporaryPath);
            $outputDimensions = @getimagesize($temporaryPath);
            $byteSize = @filesize($temporaryPath);
            $sha256 = @hash_file('sha256', $temporaryPath);
            if ($outputMime !== 'image/webp'
                || ! is_array($outputDimensions)
                || (int) ($outputDimensions[2] ?? 0) !== IMAGETYPE_WEBP
                || ! is_int($byteSize)
                || $byteSize < 1
                || ! is_string($sha256)
                || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new HttpException(503, 'De gecodeerde afbeelding kon niet worden geverifieerd.');
            }

            $thumbnailByteSize = @filesize($thumbnailTemporaryPath);
            $thumbnailSha256 = @hash_file('sha256', $thumbnailTemporaryPath);
            if (! is_int($thumbnailByteSize)
                || $thumbnailByteSize < 1
                || ! is_string($thumbnailSha256)
                || preg_match('/^[a-f0-9]{64}$/', $thumbnailSha256) !== 1) {
                throw new HttpException(503, 'De miniatuur kon niet veilig worden geverifieerd.');
            }

            return new WallboardMediaProcessedAsset(
                kind: 'image',
                temporaryPath: $temporaryPath,
                sha256: $sha256,
                mimeType: 'image/webp',
                byteSize: $byteSize,
                width: (int) $outputDimensions[0],
                height: (int) $outputDimensions[1],
                durationSeconds: null,
                thumbnailTemporaryPath: $thumbnailTemporaryPath,
                thumbnailSha256: $thumbnailSha256,
                thumbnailByteSize: $thumbnailByteSize,
            );
        } catch (Throwable $exception) {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
            if (is_string($thumbnailTemporaryPath) && is_file($thumbnailTemporaryPath)) {
                @unlink($thumbnailTemporaryPath);
            }

            throw $exception;
        } finally {
            imagedestroy($image);
        }
    }

    private function encodeThumbnail(\GdImage $image): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $maxEdge = min(max((int) config('wallboard_media.thumbnail_edge_pixels', 640), 160), 1280);
        $scale = min(1, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $thumbnail instanceof \GdImage) {
            throw new HttpException(503, 'De miniatuur kon niet worden aangemaakt.');
        }
        try {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
            imagefilledrectangle($thumbnail, 0, 0, $targetWidth, $targetHeight, $transparent);
            if (! imagecopyresampled(
                $thumbnail,
                $image,
                0,
                0,
                0,
                0,
                $targetWidth,
                $targetHeight,
                $width,
                $height,
            )) {
                throw new HttpException(503, 'De miniatuur kon niet worden verkleind.');
            }
            $path = $this->temporaryPath();
            if (! @imagewebp($thumbnail, $path, 82)) {
                @unlink($path);
                throw new HttpException(503, 'De miniatuur kon niet worden gecodeerd.');
            }
            @chmod($path, 0640);

            return $path;
        } finally {
            imagedestroy($thumbnail);
        }
    }

    private function assertGdAvailable(): void
    {
        foreach (['imagecreatefromstring', 'imagecreatetruecolor', 'imagecopyresampled', 'imagewebp'] as $function) {
            if (! function_exists($function)) {
                throw new HttpException(503, 'Beeldverwerking is tijdelijk niet beschikbaar.');
            }
        }
    }

    private function detectedMime(string $path): string
    {
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        } catch (Throwable) {
            return '';
        }

        return is_string($mime) ? strtolower(trim($mime)) : '';
    }

    private function applyOrientation(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $metadata = @exif_read_data($path, 'IFD0', true, false);
        $orientation = is_array($metadata)
            ? (int) (($metadata['IFD0']['Orientation'] ?? $metadata['Orientation'] ?? 1))
            : 1;

        if ($orientation === 2) {
            return $this->flipped($image, IMG_FLIP_HORIZONTAL);
        }
        if ($orientation === 4) {
            return $this->flipped($image, IMG_FLIP_VERTICAL);
        }
        if (! in_array($orientation, [3, 5, 6, 7, 8], true)) {
            return $image;
        }

        $rotated = $this->rotated($image, match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
        });
        try {
            if ($orientation === 5 || $orientation === 7) {
                $this->flipped($rotated, IMG_FLIP_HORIZONTAL);
            }
        } catch (Throwable $exception) {
            imagedestroy($rotated);

            throw $exception;
        }
        imagedestroy($image);

        return $rotated;
    }

    private function flipped(\GdImage $image, int $mode): \GdImage
    {
        if (! imageflip($image, $mode)) {
            throw new HttpException(503, 'De afbeeldingsoriëntatie kon niet worden verwerkt.');
        }

        return $image;
    }

    private function rotated(\GdImage $image, int $angle): \GdImage
    {
        $rotated = imagerotate($image, $angle, imagecolorallocatealpha($image, 0, 0, 0, 127));
        if (! $rotated instanceof \GdImage) {
            throw new HttpException(503, 'De afbeeldingsoriëntatie kon niet worden verwerkt.');
        }
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function resize(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $maxEdge = max(1, (int) config('wallboard_media.max_output_edge_pixels', 3840));
        if ($width <= $maxEdge && $height <= $maxEdge) {
            return $image;
        }

        $scale = min($maxEdge / $width, $maxEdge / $height);
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $resized instanceof \GdImage) {
            throw new HttpException(503, 'De afbeelding kon niet worden verkleind.');
        }
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $targetWidth, $targetHeight, $transparent);
        if (! imagecopyresampled(
            $resized,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        )) {
            imagedestroy($resized);
            throw new HttpException(503, 'De afbeelding kon niet worden verkleind.');
        }
        imagedestroy($image);

        return $resized;
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
