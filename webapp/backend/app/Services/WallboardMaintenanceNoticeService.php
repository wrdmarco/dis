<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class WallboardMaintenanceNoticeService
{
    private const MAX_NOTICE_BYTES = 1024;

    private const MAX_NOTICE_LIFETIME_SECONDS = 21600;

    private const MAX_FUTURE_CLOCK_SKEW_SECONDS = 60;

    /** @var array<string, array{title: string, message: string}> */
    private const COPY = [
        'update' => [
            'title' => 'Systeem wordt bijgewerkt',
            'message' => 'Dit wallboard blijft op de hoogte en herstelt automatisch zodra de update veilig is afgerond.',
        ],
        'maintenance' => [
            'title' => 'D.I.S. is tijdelijk in onderhoud',
            'message' => 'Dit wallboard blijft op de hoogte en herstelt automatisch zodra het onderhoud veilig is afgerond.',
        ],
    ];

    public function __construct(
        private readonly ?string $path = null,
        private readonly bool $enforceProductionFilePolicy = true,
    ) {}

    /**
     * @return array{active: true, kind: 'update'|'maintenance', title: string, message: string, started_at: string, expires_at: string}|null
     */
    public function current(): ?array
    {
        $path = $this->path ?? dirname(base_path(), 2).DIRECTORY_SEPARATOR.'maintenance'.DIRECTORY_SEPARATOR.'wallboard-status.json';
        $contents = $this->readSafeRegularFile($path);
        if ($contents === null) {
            return null;
        }

        try {
            $payload = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['active', 'expires_at', 'kind', 'started_at', 'version']
            || ($payload['version'] ?? null) !== 1
            || ($payload['active'] ?? null) !== true
            || ! is_string($payload['kind'] ?? null)
            || ! array_key_exists($payload['kind'], self::COPY)
            || ! is_string($payload['started_at'] ?? null)
            || ! is_string($payload['expires_at'] ?? null)) {
            return null;
        }

        $startedAt = $this->strictUtcTimestamp($payload['started_at']);
        $expiresAt = $this->strictUtcTimestamp($payload['expires_at']);
        if (! $startedAt instanceof CarbonImmutable || ! $expiresAt instanceof CarbonImmutable) {
            return null;
        }

        $now = CarbonImmutable::now('UTC');
        $lifetime = $expiresAt->getTimestamp() - $startedAt->getTimestamp();
        if ($lifetime < 1
            || $lifetime > self::MAX_NOTICE_LIFETIME_SECONDS
            || $startedAt->greaterThan($now->addSeconds(self::MAX_FUTURE_CLOCK_SKEW_SECONDS))
            || $expiresAt->lessThanOrEqualTo($now)) {
            return null;
        }

        $kind = $payload['kind'];

        return [
            'active' => true,
            'kind' => $kind,
            'title' => self::COPY[$kind]['title'],
            'message' => self::COPY[$kind]['message'],
            'started_at' => $startedAt->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function strictUtcTimestamp(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d\TH:i:s\Z') === $value
            ? $parsed
            : null;
    }

    private function readSafeRegularFile(string $path): ?string
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path)) {
            return null;
        }

        $before = @lstat($path);
        if (! is_array($before) || ! $this->metadataIsSafe($before)) {
            return null;
        }

        $size = $before['size'] ?? null;
        if (! is_int($size) || $size < 1 || $size > self::MAX_NOTICE_BYTES) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $opened = fstat($handle);
            if (! is_array($opened)
                || ! $this->metadataIsSafe($opened)
                || ($before['dev'] ?? null) !== ($opened['dev'] ?? null)
                || ($before['ino'] ?? null) !== ($opened['ino'] ?? null)) {
                return null;
            }

            $contents = stream_get_contents($handle, self::MAX_NOTICE_BYTES + 1);

            return is_string($contents) && strlen($contents) <= self::MAX_NOTICE_BYTES
                ? $contents
                : null;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string|int, mixed> $metadata */
    private function metadataIsSafe(array $metadata): bool
    {
        $mode = $metadata['mode'] ?? null;
        $links = $metadata['nlink'] ?? null;
        $owner = $metadata['uid'] ?? null;

        return is_int($mode)
            && $links === 1
            && (! $this->enforceProductionFilePolicy
                || (($mode & 0022) === 0 && $owner === 0));
    }
}
