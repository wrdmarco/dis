<?php

namespace App\Services;

use RuntimeException;

final class WallboardLiveStreamProcessService
{
    private const RESERVED_INGRESS_PORTS = [19350, 19351];

    public const MANIFEST_FILE = 'index.m3u8';

    public const SEGMENT_PATTERN = '/\Asegment-[0-9]{20}\.ts\z/D';

    public function enabled(): bool
    {
        return (bool) config('wallboard_live_stream.enabled', false);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     public_host: string|null,
     *     rtmps_bind_address: string,
     *     rtmps_port: int,
     *     stream_key_configured: bool,
     *     tls_certificate_path: string,
     *     tls_private_key_path: string,
     *     runtime_directory: string,
     *     output_directory: string,
     *     segment_duration_seconds: int,
     *     segment_list_size: int,
     *     manifest_stale_seconds: int,
     *     max_manifest_bytes: int,
     *     max_segment_bytes: int
     * }
     */
    public function settings(): array
    {
        $enabled = $this->enabled();
        $publicHost = trim((string) config('wallboard_live_stream.public_host', ''));
        $rtmpsBindAddress = trim((string) config('wallboard_live_stream.rtmps_bind_address', '0.0.0.0'));
        $rtmpsPort = (int) config('wallboard_live_stream.rtmps_port', 1936);
        $streamKey = (string) config('wallboard_live_stream.stream_key', '');
        $tlsCertificatePath = trim((string) config('wallboard_live_stream.tls_certificate_path', ''));
        $tlsPrivateKeyPath = trim((string) config('wallboard_live_stream.tls_private_key_path', ''));
        $delivery = $this->deliverySettings();

        if ($enabled) {
            if (! $this->isValidBindAddress($rtmpsBindAddress)) {
                throw new RuntimeException('WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS must be a valid local IPv4 address.');
            }
            if (! $this->isValidStreamKey($streamKey)) {
                throw new RuntimeException('WALLBOARD_LIVE_STREAM_STREAM_KEY must contain 32 through 79 URL-safe characters and must not consist of one repeated character.');
            }
            if (! $this->isValidRtmpsPort($rtmpsPort)) {
                throw new RuntimeException('WALLBOARD_LIVE_STREAM_RTMPS_PORT must be an available port from 1024 through 65535 and may not use an internal live-stream port.');
            }
            if ($publicHost === '' || ! $this->isValidPublicHost($publicHost)) {
                throw new RuntimeException('WALLBOARD_LIVE_STREAM_PUBLIC_HOST must contain a hostname or IPv4 address without a scheme or port.');
            }
            if (! $this->validTlsSourcePath($tlsCertificatePath)) {
                throw new RuntimeException('WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH must be an absolute managed path.');
            }
            if (! $this->validTlsSourcePath($tlsPrivateKeyPath)) {
                throw new RuntimeException('WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH must be an absolute managed path.');
            }
        }

        return [
            'enabled' => $enabled,
            'public_host' => $publicHost === '' ? null : strtolower($publicHost),
            'rtmps_bind_address' => $rtmpsBindAddress,
            'rtmps_port' => $rtmpsPort,
            'stream_key_configured' => $this->isValidStreamKey($streamKey),
            'tls_certificate_path' => $tlsCertificatePath,
            'tls_private_key_path' => $tlsPrivateKeyPath,
            ...$delivery,
        ];
    }

    /**
     * Return only the settings needed to inspect the local HLS output. This is
     * also safe to use in the request that committed a managed configuration:
     * that request can still hold the pre-reload Laravel ingest configuration.
     *
     * @return array{
     *     runtime_directory: string,
     *     output_directory: string,
     *     segment_duration_seconds: int,
     *     segment_list_size: int,
     *     manifest_stale_seconds: int,
     *     max_manifest_bytes: int,
     *     max_segment_bytes: int
     * }
     */
    public function deliverySettings(): array
    {
        $runtimeDirectory = rtrim(
            trim((string) config('wallboard_live_stream.runtime_directory', '/run/dis-wallboard-live')),
            '/\\',
        );
        $outputDirectory = rtrim(
            trim((string) config('wallboard_live_stream.output_directory', '/run/dis-wallboard-live/hls')),
            '/\\',
        );
        $segmentDuration = (int) config('wallboard_live_stream.segment_duration_seconds', 2);
        $segmentListSize = (int) config('wallboard_live_stream.segment_list_size', 6);
        $staleSeconds = (int) config('wallboard_live_stream.manifest_stale_seconds', 12);
        $maxManifestBytes = (int) config('wallboard_live_stream.max_manifest_bytes', 64 * 1024);
        $maxSegmentBytes = (int) config('wallboard_live_stream.max_segment_bytes', 6 * 1024 * 1024);

        if ($runtimeDirectory === '' || ! $this->absolutePath($runtimeDirectory)) {
            throw new RuntimeException('The wallboard live-stream runtime directory must be absolute.');
        }
        if ($outputDirectory === '' || ! $this->absolutePath($outputDirectory)
            || $this->normalizedPath($outputDirectory) !== $this->normalizedPath(
                $runtimeDirectory.DIRECTORY_SEPARATOR.'hls',
            )) {
            throw new RuntimeException('The wallboard live-stream output directory must be the absolute hls runtime subdirectory.');
        }
        if ($segmentDuration !== 2 || $segmentListSize !== 6) {
            throw new RuntimeException('The wallboard live-stream HLS window must remain fixed at six two-second segments.');
        }
        if ($staleSeconds < 6 || $staleSeconds > 120) {
            throw new RuntimeException('The wallboard live-stream stale threshold must be between 6 and 120 seconds.');
        }
        if ($maxManifestBytes < 1024 || $maxManifestBytes > 1024 * 1024
            || $maxSegmentBytes < 1024 * 1024 || $maxSegmentBytes > 256 * 1024 * 1024) {
            throw new RuntimeException('The wallboard live-stream delivery limits are invalid.');
        }

        return [
            'runtime_directory' => $runtimeDirectory,
            'output_directory' => $outputDirectory,
            'segment_duration_seconds' => $segmentDuration,
            'segment_list_size' => $segmentListSize,
            'manifest_stale_seconds' => $staleSeconds,
            'max_manifest_bytes' => $maxManifestBytes,
            'max_segment_bytes' => $maxSegmentBytes,
        ];
    }

    public function rtmpsHost(): ?string
    {
        $settings = $this->settings();
        $host = $settings['public_host'];

        return $host !== null && $this->isValidPublicHost($host) ? strtolower($host) : null;
    }

    public function isValidBindAddress(string $address): bool
    {
        return $address === '0.0.0.0' || $this->validUnicastIpv4($address);
    }

    public function isValidRtmpsPort(int $port): bool
    {
        return $port >= 1024
            && $port <= 65535
            && ! in_array($port, self::RESERVED_INGRESS_PORTS, true);
    }

    private function validUnicastIpv4(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $octets = array_map('intval', explode('.', $address));

        return count($octets) === 4
            && $octets[0] > 0
            && $octets[0] < 224
            && $address !== '255.255.255.255';
    }

    public function isValidStreamKey(string $streamKey): bool
    {
        $length = strlen($streamKey);

        return $length >= 32
            && $length <= 79
            && preg_match('/\A[A-Za-z0-9._~-]+\z/D', $streamKey) === 1
            && $streamKey !== str_repeat($streamKey[0], $length);
    }

    public function isValidPortalTlsPath(string $path): bool
    {
        return $this->validTlsSourcePath($path)
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1
            && preg_match('#\A[A-Za-z0-9._/-]+\z#D', $path) === 1
            && ! str_contains($path, '//')
            && ! in_array('.', explode('/', $path), true)
            && ! str_ends_with($path, '/')
            && (
                str_starts_with($path, '/etc/letsencrypt/live/')
                || str_starts_with($path, '/etc/ssl/')
            );
    }

    public function isValidManagedTlsPath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 4096
            && str_starts_with($path, '/')
            && preg_match('/\A[\x20-\x7E]+\z/D', $path) === 1;
    }

    private function validTlsSourcePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 4096
            && str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! str_contains($path, "\r")
            && ! str_contains($path, "\n")
            && ! in_array('..', explode('/', $path), true);
    }

    public function isValidPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->validUnicastIpv4($host);
        }
        if (preg_match('/\A(?:[0-9]{1,3}\.){3}[0-9]{1,3}\z/D', $host) === 1) {
            return false;
        }

        return strlen($host) <= 253
            && preg_match(
                '/\A(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/D',
                $host,
            ) === 1;
    }

    private function absolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (strlen($path) >= 3
                && ctype_alpha($path[0])
                && $path[1] === ':'
                && in_array($path[2], ['\\', '/'], true));
    }

    private function normalizedPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
