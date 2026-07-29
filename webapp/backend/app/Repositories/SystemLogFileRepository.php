<?php

namespace App\Repositories;

final class SystemLogFileRepository
{
    private const MAX_READ_BYTES = 128 * 1024;

    private const CHECKPOINT_BYTES = 512;

    private const SOURCE_PATTERN = '/\Alaravel(?:-\d{4}-\d{2}-\d{2})?\.log\z/D';

    /**
     * @return list<array{name: string, size_bytes: int, modified_at: string, generation: string}>
     */
    public function files(): array
    {
        $root = $this->resolvedRoot();
        if ($root === null) {
            return [];
        }

        $entries = scandir($root);
        if ($entries === false) {
            return [];
        }

        $files = [];
        foreach ($entries as $source) {
            if (! $this->sourceAllowed($source)) {
                continue;
            }

            $opened = $this->openSource($root, $source);
            if ($opened === null) {
                continue;
            }

            try {
                $files[] = $this->metadata($source, $opened['path'], $opened['stat']);
            } finally {
                fclose($opened['handle']);
            }
        }

        usort($files, static function (array $left, array $right): int {
            $modifiedComparison = strcmp($right['modified_at'], $left['modified_at']);

            return $modifiedComparison !== 0
                ? $modifiedComparison
                : strcmp($right['name'], $left['name']);
        });

        return $files;
    }

    public function latestSource(): ?string
    {
        return $this->files()[0]['name'] ?? null;
    }

    /**
     * @return array{
     *     name: string,
     *     size_bytes: int,
     *     modified_at: string,
     *     generation: string,
     *     checkpoint: string,
     *     content: string,
     *     cursor: int,
     *     reset: bool,
     *     reset_reason: string|null,
     *     truncated: bool
     * }|null
     */
    public function read(
        string $source,
        ?int $cursor,
        ?string $generation,
        ?string $checkpoint,
        string $checkpointSubject,
    ): ?array {
        if (! $this->sourceAllowed($source)) {
            return null;
        }

        $root = $this->resolvedRoot();
        if ($root === null) {
            return null;
        }

        $opened = $this->openSource($root, $source);
        if ($opened === null) {
            return null;
        }

        try {
            $metadata = $this->metadata($source, $opened['path'], $opened['stat']);
            $size = $metadata['size_bytes'];
            $generationChanged = $cursor !== null
                && $generation !== null
                && ! hash_equals($metadata['generation'], $generation);
            $fileShrank = $cursor !== null && $cursor > $size;
            $checkpointChanged = false;
            if ($cursor !== null && ! $generationChanged && ! $fileShrank && $checkpoint !== null) {
                $actualCheckpoint = $this->checkpoint(
                    $opened['handle'],
                    $cursor,
                    $metadata['generation'],
                    $checkpointSubject,
                );
                if ($actualCheckpoint === null) {
                    return null;
                }
                $checkpointChanged = ! hash_equals($actualCheckpoint, $checkpoint);
            }

            $reset = $generationChanged || $fileShrank || $checkpointChanged;
            $resetReason = $generationChanged
                ? 'rotated'
                : ($fileShrank ? 'truncated' : ($checkpointChanged ? 'replaced' : null));
            $incremental = $cursor !== null && ! $reset;
            $start = $incremental ? $cursor : max(0, $size - self::MAX_READ_BYTES);
            $truncated = ! $incremental && $start > 0;

            if ($incremental && $size - $start > self::MAX_READ_BYTES) {
                $start = $size - self::MAX_READ_BYTES;
                $truncated = true;
            }

            if ($start > 0 && (! $incremental || $start !== $cursor)) {
                $alignedStart = $this->alignToNextLine($opened['handle'], $start);
                if ($alignedStart === null) {
                    $nextCheckpoint = $this->checkpoint(
                        $opened['handle'],
                        $start,
                        $metadata['generation'],
                        $checkpointSubject,
                    );
                    if ($nextCheckpoint === null) {
                        return null;
                    }

                    return $metadata + [
                        'checkpoint' => $nextCheckpoint,
                        'content' => '',
                        'cursor' => $start,
                        'reset' => $reset,
                        'reset_reason' => $resetReason,
                        'truncated' => true,
                    ];
                }
                $start = $alignedStart;
            } elseif (fseek($opened['handle'], $start, SEEK_SET) !== 0) {
                return null;
            }

            $length = max(0, min(self::MAX_READ_BYTES, $size - $start));
            $raw = $length === 0
                ? ''
                : stream_get_contents($opened['handle'], $length);
            if ($raw === false) {
                return null;
            }

            $lastNewline = strrpos($raw, "\n");
            if ($lastNewline === false) {
                $nextCheckpoint = $this->checkpoint(
                    $opened['handle'],
                    $start,
                    $metadata['generation'],
                    $checkpointSubject,
                );
                if ($nextCheckpoint === null) {
                    return null;
                }

                return $metadata + [
                    'checkpoint' => $nextCheckpoint,
                    'content' => '',
                    'cursor' => $start,
                    'reset' => $reset,
                    'reset_reason' => $resetReason,
                    'truncated' => $truncated,
                ];
            }

            $content = substr($raw, 0, $lastNewline + 1);
            $nextCursor = $start + strlen($content);
            $nextCheckpoint = $this->checkpoint(
                $opened['handle'],
                $nextCursor,
                $metadata['generation'],
                $checkpointSubject,
            );
            if ($nextCheckpoint === null) {
                return null;
            }

            return $metadata + [
                'checkpoint' => $nextCheckpoint,
                'content' => $content,
                'cursor' => $nextCursor,
                'reset' => $reset,
                'reset_reason' => $resetReason,
                'truncated' => $truncated,
            ];
        } finally {
            fclose($opened['handle']);
        }
    }

    private function resolvedRoot(): ?string
    {
        $configured = config('dis.system_logs.directory', storage_path('logs'));
        if (! is_string($configured) || $configured === '') {
            return null;
        }

        $root = realpath($configured);

        return $root !== false && is_dir($root) ? $root : null;
    }

    private function sourceAllowed(string $source): bool
    {
        return basename($source) === $source
            && preg_match(self::SOURCE_PATTERN, $source) === 1;
    }

    /**
     * @return array{handle: resource, path: string, stat: array<string|int, int>}|null
     */
    private function openSource(string $root, string $source): ?array
    {
        $candidate = $root.DIRECTORY_SEPARATOR.$source;
        $before = @lstat($candidate);
        if (! is_array($before) || $this->unsafeStat($before) || is_link($candidate)) {
            return null;
        }

        $handle = @fopen($candidate, 'rb');
        if ($handle === false) {
            return null;
        }

        $path = realpath($candidate);
        $opened = fstat($handle);
        $after = @lstat($candidate);
        if (
            $path === false
            || ! is_array($opened)
            || ! is_array($after)
            || $this->unsafeStat($opened)
            || $this->unsafeStat($after)
            || is_link($candidate)
            || ! $this->samePath(dirname($path), $root)
            || ! $this->sameFile($before, $opened)
            || ! $this->sameFile($opened, $after)
        ) {
            fclose($handle);

            return null;
        }

        return [
            'handle' => $handle,
            'path' => $path,
            'stat' => $opened,
        ];
    }

    /**
     * @param  array<string|int, int>  $stat
     */
    private function unsafeStat(array $stat): bool
    {
        $mode = (int) ($stat['mode'] ?? $stat[2] ?? 0);
        $links = (int) ($stat['nlink'] ?? $stat[3] ?? 0);
        $isRegularFile = ($mode & 0170000) === 0100000;
        $worldWritable = PHP_OS_FAMILY !== 'Windows' && ($mode & 0002) !== 0;

        return ! $isRegularFile || $links !== 1 || $worldWritable;
    }

    /**
     * @param  array<string|int, int>  $left
     * @param  array<string|int, int>  $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return (int) ($left['dev'] ?? $left[0] ?? -1) === (int) ($right['dev'] ?? $right[0] ?? -2)
            && (int) ($left['ino'] ?? $left[1] ?? -1) === (int) ($right['ino'] ?? $right[1] ?? -2);
    }

    private function samePath(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    /**
     * @param  resource  $handle
     */
    private function alignToNextLine($handle, int $offset): ?int
    {
        if (fseek($handle, $offset - 1, SEEK_SET) !== 0) {
            return null;
        }

        if (fread($handle, 1) === "\n") {
            return fseek($handle, $offset, SEEK_SET) === 0 ? $offset : null;
        }

        if (fseek($handle, $offset, SEEK_SET) !== 0) {
            return null;
        }

        $discarded = fgets($handle, self::MAX_READ_BYTES + 1);
        if ($discarded === false || ! str_ends_with($discarded, "\n")) {
            return null;
        }

        $position = ftell($handle);

        return is_int($position) ? $position : null;
    }

    /**
     * @param  resource  $handle
     */
    private function checkpoint(
        $handle,
        int $offset,
        string $generation,
        string $subject,
    ): ?string {
        if (fseek($handle, 0, SEEK_SET) !== 0) {
            return null;
        }
        $prefix = fgets($handle, self::CHECKPOINT_BYTES + 1);
        if ($prefix === false) {
            $prefix = '';
        }

        $start = max(0, $offset - self::CHECKPOINT_BYTES);
        if (fseek($handle, $start, SEEK_SET) !== 0) {
            return null;
        }

        $context = stream_get_contents($handle, $offset - $start);
        if ($context === false) {
            return null;
        }

        return hash_hmac(
            'sha256',
            $subject."\0".$generation."\0".$offset."\0".$prefix."\0".$context,
            (string) config('app.key', ''),
        );
    }

    /**
     * @param  array<string|int, int>  $stat
     * @return array{name: string, size_bytes: int, modified_at: string, generation: string}
     */
    private function metadata(string $source, string $path, array $stat): array
    {
        $size = max(0, (int) ($stat['size'] ?? $stat[7] ?? 0));
        $modifiedAt = max(0, (int) ($stat['mtime'] ?? $stat[9] ?? 0));
        $device = (int) ($stat['dev'] ?? $stat[0] ?? 0);
        $inode = (int) ($stat['ino'] ?? $stat[1] ?? 0);

        return [
            'name' => $source,
            'size_bytes' => $size,
            'modified_at' => gmdate('Y-m-d\TH:i:s\Z', $modifiedAt),
            'generation' => hash('sha256', $path."\0".$device."\0".$inode),
        ];
    }
}
