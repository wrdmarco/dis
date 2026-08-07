<?php

namespace App\Services;

use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Repositories\WallboardRepository;
use Carbon\CarbonImmutable;
use Throwable;

final class WallboardLiveStreamDeliveryService
{
    private const INTERNAL_SEGMENT_PREFIX = '/__dis_wallboard_live/';

    private const RETAINED_SEGMENT_GRACE = 2;

    public function __construct(
        private readonly WallboardLiveStreamProcessService $process,
        private readonly WallboardLiveStreamKeyService $keys,
        private readonly WallboardLiveStreamConfigurationService $configuration,
        private readonly WallboardRepository $wallboards,
        private readonly WallboardPlaylistResolver $playlists,
    ) {}

    /** @return array{status: string, manifest_url: string|null, last_packet_at: string|null, message: string|null}|null */
    public function statusForWallboard(Wallboard $wallboard): ?array
    {
        if (! $this->authorized($wallboard)) {
            return null;
        }
        $status = $this->streamStatus();

        return [
            'status' => $status['status'],
            'manifest_url' => $status['status'] === 'live'
                ? '/api/wallboard/live-stream/manifest.m3u8'
                : null,
            'last_packet_at' => $status['last_packet_at'],
            'message' => $status['message'],
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     server_url: string|null,
     *     stream_key_configured: bool,
     *     stream_key_version: string|null,
     *     configuration_revision: string,
     *     last_packet_at: string|null,
     *     message: string|null,
     *     configuration: array{
     *         enabled: bool,
     *         public_host: string,
     *         rtmps_bind_address: string,
     *         rtmps_port: int,
     *         tls_certificate_path: string,
     *         tls_private_key_path: string
     *     }
     * }
     */
    public function statusForAdmin(bool $managedConfigurationJustCommitted = false): array
    {
        $configurationState = $this->configuration->statusState();
        $configuration = $configurationState['configuration'];
        $status = $this->streamStatus(
            $configuration['enabled'],
            $managedConfigurationJustCommitted,
        );
        $serverUrl = null;
        if ($configuration['enabled']
            && $configuration['public_host'] !== ''
            && $this->process->isValidPublicHost($configuration['public_host'])
            && $this->process->isValidRtmpsPort($configuration['rtmps_port'])) {
            $serverUrl = sprintf(
                'rtmps://%s:%d/live',
                $configuration['public_host'],
                $configuration['rtmps_port'],
            );
        }
        $streamKeyVersion = $this->keys->streamKeyVersion();

        return [
            'status' => $status['status'],
            'server_url' => $serverUrl,
            'stream_key_configured' => $configurationState['stream_key_configured'],
            'stream_key_version' => $streamKeyVersion,
            'configuration_revision' => $configurationState['configuration_revision'],
            'last_packet_at' => $status['last_packet_at'],
            'message' => $status['message'],
            'configuration' => $configuration,
        ];
    }

    public function manifestForWallboard(Wallboard $wallboard): ?string
    {
        return $this->authorized($wallboard) ? $this->manifest() : null;
    }

    public function manifestForAdmin(): ?string
    {
        return $this->manifest();
    }

    /** @return array{x_accel_redirect: string}|null */
    public function segmentForWallboard(Wallboard $wallboard, string $segment): ?array
    {
        return $this->authorized($wallboard) ? $this->segment($segment) : null;
    }

    /** @return array{x_accel_redirect: string}|null */
    public function segmentForAdmin(string $segment): ?array
    {
        return $this->segment($segment);
    }

    /** @return array{status: string, last_packet_at: string|null, message: string|null} */
    private function streamStatus(
        ?bool $enabled = null,
        bool $useDeliveryOnlySettings = false,
    ): array {
        if (! ($enabled ?? $this->process->enabled())) {
            return [
                'status' => 'offline',
                'last_packet_at' => null,
                'message' => 'De OBS-live stream is niet ingeschakeld.',
            ];
        }

        try {
            $settings = $useDeliveryOnlySettings
                ? $this->process->deliverySettings()
                : $this->process->settings();
            $inspection = $this->inspectManifest($settings);
        } catch (Throwable) {
            return [
                'status' => 'error',
                'last_packet_at' => null,
                'message' => 'De live-streamconfiguratie is ongeldig.',
            ];
        }

        $lastActivity = $inspection['last_activity_at'] === null
            ? null
            : CarbonImmutable::createFromTimestampUTC($inspection['last_activity_at'])->format(DATE_ATOM);

        return match ($inspection['state']) {
            'valid' => [
                'status' => 'live',
                'last_packet_at' => $lastActivity,
                'message' => null,
            ],
            'missing' => [
                'status' => 'waiting',
                'last_packet_at' => null,
                'message' => 'Wachten op een geldig OBS-signaal.',
            ],
            'stale' => [
                'status' => 'waiting',
                'last_packet_at' => $lastActivity,
                'message' => 'De OBS-stream is onderbroken; er wordt opnieuw gewacht op beeld.',
            ],
            default => [
                'status' => 'error',
                'last_packet_at' => $lastActivity,
                'message' => 'De tijdelijke live-streamuitvoer is ongeldig.',
            ],
        };
    }

    private function manifest(): ?string
    {
        if (! $this->process->enabled()) {
            return null;
        }
        try {
            $inspection = $this->inspectManifest($this->process->settings());
        } catch (Throwable) {
            return null;
        }

        return $inspection['state'] === 'valid' ? $inspection['body'] : null;
    }

    /** @return array{x_accel_redirect: string}|null */
    private function segment(string $segment): ?array
    {
        if (preg_match(WallboardLiveStreamProcessService::SEGMENT_PATTERN, $segment) !== 1
            || ! $this->process->enabled()) {
            return null;
        }
        try {
            $settings = $this->process->settings();
            $inspection = $this->inspectManifest($settings);
        } catch (Throwable) {
            return null;
        }
        if ($inspection['state'] !== 'valid') {
            return null;
        }
        if (! in_array($segment, $inspection['segments'], true)) {
            if (! $this->isRetainedGraceSegment($segment, $inspection['segments'])) {
                return null;
            }
            $directory = $this->safeDirectory($settings['output_directory']);
            if (! is_string($directory)) {
                return null;
            }
            $metadata = $this->regularFileMetadata($directory, $segment, $settings['max_segment_bytes']);
            $now = now()->getTimestamp();
            $retainedFreshnessSeconds = (
                $settings['segment_list_size'] + self::RETAINED_SEGMENT_GRACE
            ) * $settings['segment_duration_seconds'] + 5;
            if ($metadata === null
                || $metadata['mtime'] > $now + 5
                || $inspection['last_activity_at'] === null
                || $metadata['mtime'] < $inspection['last_activity_at'] - $retainedFreshnessSeconds) {
                return null;
            }
        }

        return ['x_accel_redirect' => self::INTERNAL_SEGMENT_PREFIX.$segment];
    }

    private function authorized(Wallboard $wallboard): bool
    {
        if (! $wallboard->is_enabled) {
            return false;
        }
        $current = $this->wallboards->findWithRuntimePlaylists((string) $wallboard->getKey());
        if (! $current instanceof Wallboard || ! $current->is_enabled) {
            return false;
        }

        try {
            $base = $this->playlists->resolveRuntime($current, false);
            if ($base['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO) {
                return false;
            }
            if ($this->hasLiveStreamPage($base['configuration'])) {
                return true;
            }

            $alarm = $this->playlists->resolveRuntime($current, true);

            return $alarm['active_deployment_playlist'] === true
                && $alarm['data_mode'] === WallboardPlaylist::DATA_MODE_LIVE
                && $this->hasLiveStreamPage($alarm['configuration']);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $configuration */
    private function hasLiveStreamPage(array $configuration): bool
    {
        foreach ((array) ($configuration['pages'] ?? []) as $page) {
            if (is_array($page) && ($page['type'] ?? null) === 'live_stream') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{output_directory: string, manifest_stale_seconds: int, max_manifest_bytes: int, max_segment_bytes: int, segment_duration_seconds: int, segment_list_size: int}  $settings
     * @return array{state: string, body: string|null, segments: list<string>, last_activity_at: int|null}
     */
    private function inspectManifest(array $settings): array
    {
        $directory = $this->safeDirectory($settings['output_directory']);
        if ($directory === false) {
            return $this->inspection('invalid');
        }
        if ($directory === null) {
            return $this->inspection('missing');
        }
        $manifestPath = $directory.DIRECTORY_SEPARATOR.WallboardLiveStreamProcessService::MANIFEST_FILE;
        if (! file_exists($manifestPath) && ! is_link($manifestPath)) {
            return $this->inspection('missing');
        }
        $manifest = $this->readRegularFile(
            $directory,
            WallboardLiveStreamProcessService::MANIFEST_FILE,
            $settings['max_manifest_bytes'],
        );
        if ($manifest === null) {
            return $this->inspection('invalid');
        }
        $segments = $this->manifestSegments($manifest['body'], $settings['segment_list_size']);
        if ($segments === null) {
            return $this->inspection('invalid', lastActivityAt: $manifest['mtime']);
        }

        $lastActivityAt = $manifest['mtime'];
        foreach ($segments as $segment) {
            $metadata = $this->regularFileMetadata($directory, $segment, $settings['max_segment_bytes']);
            if ($metadata === null) {
                return $this->inspection('invalid', lastActivityAt: $lastActivityAt);
            }
            $lastActivityAt = max($lastActivityAt, $metadata['mtime']);
        }
        $now = now()->getTimestamp();
        if ($lastActivityAt > $now + 5) {
            return $this->inspection('invalid', lastActivityAt: $lastActivityAt);
        }
        if ($lastActivityAt < $now - $settings['manifest_stale_seconds']) {
            return $this->inspection(
                'stale',
                $manifest['body'],
                $segments,
                $lastActivityAt,
            );
        }

        return $this->inspection('valid', $manifest['body'], $segments, $lastActivityAt);
    }

    /**
     * @return array{state: string, body: string|null, segments: list<string>, last_activity_at: int|null}
     */
    private function inspection(
        string $state,
        ?string $body = null,
        array $segments = [],
        ?int $lastActivityAt = null,
    ): array {
        return [
            'state' => $state,
            'body' => $body,
            'segments' => $segments,
            'last_activity_at' => $lastActivityAt,
        ];
    }

    private function safeDirectory(string $directory): string|false|null
    {
        if (is_link($directory)) {
            return false;
        }
        if (! file_exists($directory)) {
            return null;
        }
        if (! is_dir($directory)) {
            return false;
        }
        $real = realpath($directory);
        if (! is_string($real) || $this->normalizedPath($real) !== $this->normalizedPath($directory)) {
            return false;
        }

        return $real;
    }

    /** @return array{body: string, mtime: int}|null */
    private function readRegularFile(string $directory, string $filename, int $maximumBytes): ?array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $metadata = $this->regularFileMetadata($directory, $filename, $maximumBytes);
            if ($metadata === null) {
                continue;
            }
            $stream = @fopen($metadata['path'], 'rb');
            if (! is_resource($stream)) {
                continue;
            }
            try {
                $opened = fstat($stream);
                if (! is_array($opened)
                    || (int) ($opened['size'] ?? -1) !== $metadata['size']
                    || (int) ($opened['mtime'] ?? -1) !== $metadata['mtime']
                    || (isset($metadata['ino'], $opened['ino']) && (int) $opened['ino'] !== $metadata['ino'])
                    || (isset($metadata['dev'], $opened['dev']) && (int) $opened['dev'] !== $metadata['dev'])) {
                    continue;
                }
                $body = stream_get_contents($stream, $maximumBytes + 1);
                if (! is_string($body)
                    || strlen($body) !== $metadata['size']
                    || strlen($body) > $maximumBytes
                    || str_contains($body, "\0")) {
                    continue;
                }

                return ['body' => $body, 'mtime' => $metadata['mtime']];
            } finally {
                fclose($stream);
            }
        }

        return null;
    }

    /** @return array{path: string, size: int, mtime: int, ino?: int, dev?: int}|null */
    private function regularFileMetadata(string $directory, string $filename, int $maximumBytes): ?array
    {
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! is_array($stat)
            || is_link($path)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (int) ($stat['nlink'] ?? 0) !== 1) {
            return null;
        }
        $size = (int) ($stat['size'] ?? 0);
        $mtime = (int) ($stat['mtime'] ?? 0);
        if ($size < 1 || $size > $maximumBytes || $mtime < 1) {
            return null;
        }
        $real = realpath($path);
        $expected = $directory.DIRECTORY_SEPARATOR.$filename;
        if (! is_string($real) || $this->normalizedPath($real) !== $this->normalizedPath($expected)) {
            return null;
        }

        return [
            'path' => $real,
            'size' => $size,
            'mtime' => $mtime,
            ...(isset($stat['ino']) ? ['ino' => (int) $stat['ino']] : []),
            ...(isset($stat['dev']) ? ['dev' => (int) $stat['dev']] : []),
        ];
    }

    /** @return list<string>|null */
    private function manifestSegments(string $manifest, int $maximumSegments): ?array
    {
        $lines = preg_split('/\r?\n/', $manifest);
        if (! is_array($lines) || count($lines) > 256 || ($lines[0] ?? null) !== '#EXTM3U') {
            return null;
        }
        $segments = [];
        $expectsSegment = false;
        $hasTargetDuration = false;
        $mediaSequence = null;
        foreach ($lines as $index => $line) {
            if ($index === 0 || $line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                if ($expectsSegment) {
                    return null;
                }
                if (preg_match('/\A#EXTINF:[0-9]+(?:\.[0-9]{1,6})?,\z/D', $line) === 1) {
                    $expectsSegment = true;

                    continue;
                }
                if (preg_match('/\A#EXT-X-TARGETDURATION:[0-9]{1,4}\z/D', $line) === 1) {
                    $hasTargetDuration = true;

                    continue;
                }
                if (preg_match('/\A#EXT-X-MEDIA-SEQUENCE:([0-9]{1,20})\z/D', $line, $matches) === 1) {
                    if ($mediaSequence !== null) {
                        return null;
                    }
                    $mediaSequence = str_pad($matches[1], 20, '0', STR_PAD_LEFT);

                    continue;
                }
                if (preg_match('/\A#EXT-X-(?:VERSION:[0-9]{1,2}|ALLOW-CACHE:NO|INDEPENDENT-SEGMENTS|DISCONTINUITY|DISCONTINUITY-SEQUENCE:[0-9]{1,20})\z/D', $line) === 1) {
                    continue;
                }

                return null;
            }
            if (! $expectsSegment
                || preg_match('/\Asegments\/(segment-[0-9]{20}\.ts)\z/D', $line, $matches) !== 1
                || in_array($matches[1], $segments, true)) {
                return null;
            }
            $sequence = $this->segmentSequence($matches[1]);
            $expectedSequence = $segments === []
                ? $mediaSequence
                : $this->incrementSequence($this->segmentSequence($segments[array_key_last($segments)]));
            if ($sequence === null || $expectedSequence === null || $sequence !== $expectedSequence) {
                return null;
            }
            $segments[] = $matches[1];
            $expectsSegment = false;
        }

        return ! $expectsSegment
            && $hasTargetDuration
            && $mediaSequence !== null
            && $segments !== []
            && count($segments) <= $maximumSegments
                ? $segments
                : null;
    }

    /** @param list<string> $currentSegments */
    private function isRetainedGraceSegment(string $segment, array $currentSegments): bool
    {
        $candidate = $this->segmentSequence($segment);
        $oldest = $this->segmentSequence($currentSegments[0] ?? '');
        if ($candidate === null || $oldest === null) {
            return false;
        }

        for ($offset = 0; $offset < self::RETAINED_SEGMENT_GRACE; $offset++) {
            $oldest = $this->decrementSequence($oldest);
            if ($oldest === null) {
                return false;
            }
            if (hash_equals($oldest, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function segmentSequence(string $segment): ?string
    {
        return preg_match(WallboardLiveStreamProcessService::SEGMENT_PATTERN, $segment) === 1
            ? substr($segment, 8, 20)
            : null;
    }

    private function incrementSequence(?string $sequence): ?string
    {
        if ($sequence === null || preg_match('/\A[0-9]{20}\z/D', $sequence) !== 1) {
            return null;
        }
        $digits = str_split($sequence);
        for ($index = 19; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return implode('', $digits);
            }
            $digits[$index] = '0';
        }

        return null;
    }

    private function decrementSequence(string $sequence): ?string
    {
        if (preg_match('/\A[0-9]{20}\z/D', $sequence) !== 1) {
            return null;
        }
        $digits = str_split($sequence);
        for ($index = 19; $index >= 0; $index--) {
            if ($digits[$index] !== '0') {
                $digits[$index] = (string) ((int) $digits[$index] - 1);

                return implode('', $digits);
            }
            $digits[$index] = '9';
        }

        return null;
    }

    private function normalizedPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
