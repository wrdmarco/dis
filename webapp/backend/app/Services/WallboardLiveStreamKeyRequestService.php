<?php

namespace App\Services;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;

final class WallboardLiveStreamKeyRequestService
{
    public const WAIT_TIMEOUT_SECONDS = 105;

    public const REQUEST_TTL_SECONDS = 120;

    private const POLL_INTERVAL_MICROSECONDS = 250_000;

    private const MAX_RESULT_BYTES = 65_536;

    public function __construct(
        private readonly ?string $requestRootOverride = null,
        private readonly ?Closure $requestIdGenerator = null,
        private readonly ?Closure $monotonicClock = null,
        private readonly ?Closure $sleeper = null,
        private readonly ?Closure $pathMetadataReader = null,
    ) {}

    /**
     * @return array{request_id: string, exit_code: int|null, outcome: string}
     */
    public function rotate(
        string $streamKey,
        string $expectedKeySha256,
        string $actorId,
        int $waitSeconds = self::WAIT_TIMEOUT_SECONDS,
    ): array {
        if (preg_match('/\A[A-Za-z0-9_-]{64}\z/D', $streamKey) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $expectedKeySha256) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/Di', $actorId) !== 1
            || $waitSeconds < 1
            || $waitSeconds > self::WAIT_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException('Invalid wallboard live-stream key rotation request.');
        }

        $requestId = $this->newRequestId();
        $root = $this->requestRoot();
        if (is_link($root) || ! is_dir($root) || ! is_writable($root)) {
            return $this->result($requestId, null, 'request_directory_unavailable');
        }

        $temporary = $root.DIRECTORY_SEPARATOR.$requestId.'.tmp';
        $pending = $root.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $result = $root.DIRECTORY_SEPARATOR.$requestId.'.result';
        $createdAt = time();
        $payload = json_encode([
            'operation' => 'rotate',
            'stream_key' => $streamKey,
            'expected_key_sha256' => $expectedKeySha256,
            'actor_id' => $actorId,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $createdAt),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $createdAt + self::REQUEST_TTL_SECONDS),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

        try {
            $this->publish($temporary, $pending, $payload);
        } catch (Throwable $exception) {
            if (is_file($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
            report($exception);

            return $this->result($requestId, null, 'publication_failed');
        }

        $deadline = $this->now() + $waitSeconds;
        $claimed = false;
        while (true) {
            clearstatcache(true, $result);
            if (is_file($result) || is_link($result)) {
                return $this->readResult($requestId, $result, $streamKey, $expectedKeySha256);
            }

            clearstatcache(true, $pending);
            if (! is_file($pending)) {
                $claimed = true;
            }
            if ($this->now() >= $deadline) {
                break;
            }
            $this->sleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        clearstatcache(true, $result);
        if (is_file($result) || is_link($result)) {
            return $this->readResult($requestId, $result, $streamKey, $expectedKeySha256);
        }

        clearstatcache(true, $pending);
        if (! $claimed && is_file($pending) && @unlink($pending)) {
            return $this->result($requestId, 124, 'timeout_unclaimed');
        }

        clearstatcache(true, $result);
        if (is_file($result) || is_link($result)) {
            return $this->readResult($requestId, $result, $streamKey, $expectedKeySha256);
        }

        return $this->result($requestId, 124, 'timeout_claimed');
    }

    private function publish(string $temporary, string $pending, string $payload): void
    {
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($temporary, 'xb');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('Exclusive wallboard live-stream key request staging failed.');
        }

        $completed = false;
        try {
            $offset = 0;
            while ($offset < strlen($payload)) {
                $written = fwrite($handle, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Wallboard live-stream key request could not be written completely.');
                }
                $offset += $written;
            }
            if (function_exists('fchmod') && ! fchmod($handle, 0600)) {
                throw new RuntimeException('Wallboard live-stream key request permissions could not be restricted.');
            }
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Wallboard live-stream key request could not be stored durably.');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                $metadata = fstat($handle);
                $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
                if ($metadata === false
                    || ($metadata['mode'] & 0170000) !== 0100000
                    || ($metadata['mode'] & 0777) !== 0600
                    || $metadata['nlink'] !== 1
                    || ($effectiveUid !== null && $metadata['uid'] !== $effectiveUid)) {
                    throw new RuntimeException('Wallboard live-stream key request staging metadata is unsafe.');
                }
            }
            $completed = true;
        } finally {
            fclose($handle);
            if (! $completed) {
                @unlink($temporary);
            }
        }

        if (file_exists($pending) || is_link($pending) || ! @rename($temporary, $pending)) {
            @unlink($temporary);
            throw new RuntimeException('Wallboard live-stream key request could not be published atomically.');
        }
    }

    /** @return array{request_id: string, exit_code: int|null, outcome: string} */
    private function readResult(
        string $requestId,
        string $path,
        string $streamKey,
        string $expectedKeySha256,
    ): array {
        try {
            $pathMetadata = $this->pathMetadata($path);
            $handle = @fopen($path, 'rb');
            if ($pathMetadata === false || $handle === false) {
                throw new RuntimeException('Wallboard live-stream key result could not be opened safely.');
            }
            $locked = false;
            try {
                if (! flock($handle, LOCK_SH)) {
                    throw new RuntimeException('Wallboard live-stream key result could not be locked safely.');
                }
                $locked = true;
                $pathMetadata = $this->pathMetadata($path);
                $metadata = fstat($handle);
                $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
                if ($pathMetadata === false
                    || $metadata === false
                    || ($pathMetadata['mode'] & 0170000) !== 0100000
                    || ($metadata['mode'] & 0170000) !== 0100000
                    || $metadata['nlink'] !== 1
                    || $metadata['size'] < 2
                    || $metadata['size'] > self::MAX_RESULT_BYTES
                    || ($pathMetadata['dev'] ?? null) !== ($metadata['dev'] ?? null)
                    || ($pathMetadata['ino'] ?? null) !== ($metadata['ino'] ?? null)
                    || (PHP_OS_FAMILY !== 'Windows' && ($metadata['mode'] & 0777) !== 0600)
                    || ($effectiveUid !== null && $metadata['uid'] !== $effectiveUid)) {
                    throw new RuntimeException('Wallboard live-stream key result metadata is unsafe.');
                }
                $contents = '';
                while (! feof($handle)) {
                    $chunk = fread($handle, min(8192, self::MAX_RESULT_BYTES + 1 - strlen($contents)));
                    if ($chunk === false) {
                        throw new RuntimeException('Wallboard live-stream key result could not be read completely.');
                    }
                    $contents .= $chunk;
                    if (strlen($contents) > self::MAX_RESULT_BYTES) {
                        throw new RuntimeException('Wallboard live-stream key result exceeded its size limit.');
                    }
                }
            } finally {
                if ($locked) {
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
            }
            if (strlen($contents) !== $metadata['size']
                || str_contains($contents, $streamKey)
                || str_contains($contents, $expectedKeySha256)) {
                throw new RuntimeException('Wallboard live-stream key result is invalid or contains sensitive data.');
            }
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)
                || ! in_array($decoded['state'] ?? null, ['succeeded', 'failed'], true)
                || ! is_int($decoded['exit_code'] ?? null)
                || ! is_string($decoded['output'] ?? null)
                || strlen($decoded['output']) > 4000
                || ! is_string($decoded['finished_at'] ?? null)
                || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $decoded['finished_at']) !== 1
                || (($decoded['exit_code'] === 0) !== ($decoded['state'] === 'succeeded'))) {
                throw new RuntimeException('Wallboard live-stream key result has an invalid structure.');
            }
            $exitCode = $decoded['exit_code'];
            $outcome = $exitCode === 0 ? 'succeeded' : ($exitCode === 3 ? 'conflict' : 'worker_failed');
        } catch (JsonException|RuntimeException $exception) {
            report($exception);
            $exitCode = null;
            $outcome = 'invalid_result';
        }

        if ((is_file($path) || is_link($path)) && ! @unlink($path)) {
            report(new RuntimeException("Processed wallboard live-stream key result could not be removed: {$requestId}"));
        }

        return $this->result($requestId, $exitCode, $outcome);
    }

    /** @return array{request_id: string, exit_code: int|null, outcome: string} */
    private function result(string $requestId, ?int $exitCode, string $outcome): array
    {
        return ['request_id' => $requestId, 'exit_code' => $exitCode, 'outcome' => $outcome];
    }

    private function requestRoot(): string
    {
        return rtrim(
            $this->requestRootOverride
                ?? (string) config('wallboard_live_stream.key_request_directory', ''),
            '/\\',
        );
    }

    private function newRequestId(): string
    {
        $requestId = $this->requestIdGenerator === null
            ? bin2hex(random_bytes(16))
            : ($this->requestIdGenerator)();
        if (! is_string($requestId) || preg_match('/\A[a-f0-9]{32}\z/D', $requestId) !== 1) {
            throw new RuntimeException('Wallboard live-stream key request id is invalid.');
        }

        return $requestId;
    }

    private function now(): float
    {
        if ($this->monotonicClock === null) {
            return hrtime(true) / 1_000_000_000;
        }
        $value = ($this->monotonicClock)();
        if (! is_int($value) && ! is_float($value)) {
            throw new RuntimeException('Wallboard live-stream key request clock is invalid.');
        }

        return (float) $value;
    }

    private function sleep(int $microseconds): void
    {
        if ($this->sleeper === null) {
            usleep($microseconds);

            return;
        }
        ($this->sleeper)($microseconds);
    }

    /** @return array<string|int, int>|false */
    private function pathMetadata(string $path): array|false
    {
        if ($this->pathMetadataReader === null) {
            return @lstat($path);
        }
        $metadata = ($this->pathMetadataReader)($path);
        if ($metadata !== false && ! is_array($metadata)) {
            throw new RuntimeException('Wallboard live-stream key result metadata reader is invalid.');
        }

        return $metadata;
    }
}
