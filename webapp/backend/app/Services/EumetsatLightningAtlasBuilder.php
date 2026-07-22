<?php

namespace App\Services;

final class EumetsatLightningAtlasBuilder
{
    public function __construct(private readonly EumetsatLightningConfiguration $configuration) {}

    /**
     * @param  list<string>  $framePaths
     * @return array{path: string, size_bytes: int, sha256: string, width: int, height: int}
     */
    public function build(string $stagingDirectory, array $framePaths): array
    {
        if (count($framePaths) !== $this->configuration->frameCount()) {
            throw new EumetsatLightningImportException(
                'frame_set_incomplete',
                'The EUMETSAT lightning atlas requires exactly seven frames.',
            );
        }
        foreach ([
            'imagecreatetruecolor',
            'imagecreatefromstring',
            'imagealphablending',
            'imagesavealpha',
            'imagecolorallocatealpha',
            'imagefilledrectangle',
            'imagecopy',
            'imagepng',
            'imagedestroy',
        ] as $function) {
            if (! function_exists($function)) {
                throw new EumetsatLightningImportException(
                    'image_runtime_unavailable',
                    'The PHP GD runtime required for the EUMETSAT lightning atlas is unavailable.',
                );
            }
        }

        $atlas = @imagecreatetruecolor(
            $this->configuration->atlasWidth(),
            $this->configuration->atlasHeight(),
        );
        if ($atlas === false) {
            throw new EumetsatLightningImportException(
                'atlas_build_failed',
                'The EUMETSAT lightning atlas canvas could not be created.',
            );
        }
        try {
            imagealphablending($atlas, false);
            imagesavealpha($atlas, true);
            $transparent = imagecolorallocatealpha($atlas, 0, 0, 0, 127);
            if ($transparent === false
                || ! imagefilledrectangle(
                    $atlas,
                    0,
                    0,
                    $this->configuration->atlasWidth() - 1,
                    $this->configuration->atlasHeight() - 1,
                    $transparent,
                )) {
                throw new EumetsatLightningImportException(
                    'atlas_build_failed',
                    'The transparent EUMETSAT lightning atlas could not be initialized.',
                );
            }

            foreach ($framePaths as $index => $framePath) {
                $body = $this->validatedFrameBody($framePath);
                $frame = @imagecreatefromstring($body);
                if ($frame === false) {
                    throw new EumetsatLightningImportException(
                        'frame_content_invalid',
                        'An EUMETSAT lightning frame could not be decoded by GD.',
                    );
                }
                try {
                    if (imagesx($frame) !== $this->configuration->frameWidth()
                        || imagesy($frame) !== $this->configuration->frameHeight()) {
                        throw new EumetsatLightningImportException(
                            'frame_content_invalid',
                            'An EUMETSAT lightning frame changed dimensions before atlas assembly.',
                        );
                    }
                    $column = $index % $this->configuration->atlasColumns();
                    $row = intdiv($index, $this->configuration->atlasColumns());
                    if ($row >= $this->configuration->atlasRows()
                        || ! imagecopy(
                            $atlas,
                            $frame,
                            $column * $this->configuration->frameWidth(),
                            $row * $this->configuration->frameHeight(),
                            0,
                            0,
                            $this->configuration->frameWidth(),
                            $this->configuration->frameHeight(),
                        )) {
                        throw new EumetsatLightningImportException(
                            'atlas_build_failed',
                            'An EUMETSAT lightning frame could not be copied into the atlas.',
                        );
                    }
                } finally {
                    imagedestroy($frame);
                }
            }

            $atlasPath = $stagingDirectory.DIRECTORY_SEPARATOR.'lightning-atlas.png';
            if (! @imagepng($atlas, $atlasPath, 6)) {
                throw new EumetsatLightningImportException(
                    'atlas_build_failed',
                    'The EUMETSAT lightning atlas could not be encoded as PNG.',
                );
            }
            @chmod($atlasPath, 0640);
        } finally {
            imagedestroy($atlas);
        }

        clearstatcache(true, $atlasPath);
        $size = @filesize($atlasPath);
        $dimensions = @getimagesize($atlasPath);
        $sha256 = @hash_file('sha256', $atlasPath);
        if (! is_int($size)
            || $size < 67
            || $size > $this->configuration->maximumAtlasBytes()
            || ! is_array($dimensions)
            || ($dimensions[0] ?? null) !== $this->configuration->atlasWidth()
            || ($dimensions[1] ?? null) !== $this->configuration->atlasHeight()
            || ($dimensions[2] ?? null) !== IMAGETYPE_PNG
            || ! is_string($sha256)
            || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            @unlink($atlasPath);
            throw new EumetsatLightningImportException(
                'atlas_build_failed',
                'The encoded EUMETSAT lightning atlas failed integrity validation.',
            );
        }

        foreach ($framePaths as $framePath) {
            @unlink($framePath);
        }

        return [
            'path' => $atlasPath,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'width' => $this->configuration->atlasWidth(),
            'height' => $this->configuration->atlasHeight(),
        ];
    }

    private function validatedFrameBody(string $path): string
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if (! is_int($size)
            || $size < 67
            || $size > $this->configuration->maximumFrameBytes()
            || is_link($path)
            || ! is_file($path)
            || ! is_readable($path)) {
            throw new EumetsatLightningImportException(
                'frame_content_invalid',
                'A staged EUMETSAT lightning frame is unsafe.',
            );
        }
        $body = @file_get_contents($path);
        if (! is_string($body)
            || strlen($body) !== $size
            || ! str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
            throw new EumetsatLightningImportException(
                'frame_content_invalid',
                'A staged EUMETSAT lightning frame failed its PNG integrity check.',
            );
        }

        return $body;
    }
}
