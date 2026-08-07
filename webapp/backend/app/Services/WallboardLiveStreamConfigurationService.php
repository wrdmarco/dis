<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

final class WallboardLiveStreamConfigurationService
{
    private const MAX_ENV_BYTES = 2_097_152;

    private const ENV_KEYS = [
        'enabled' => 'WALLBOARD_LIVE_STREAM_ENABLED',
        'public_host' => 'WALLBOARD_LIVE_STREAM_PUBLIC_HOST',
        'bind_address' => 'WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS',
        'rtmps_port' => 'WALLBOARD_LIVE_STREAM_RTMPS_PORT',
        'tls_certificate_path' => 'WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH',
        'tls_private_key_path' => 'WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH',
        'stream_key' => 'WALLBOARD_LIVE_STREAM_STREAM_KEY',
    ];

    public function __construct(
        private readonly WallboardLiveStreamProcessService $process,
        private readonly WallboardLiveStreamKeyRequestService $requests,
        private readonly AuditService $auditService,
    ) {}

    /**
     * @return array{
     *     configuration: array{enabled: bool, public_host: string, rtmps_bind_address: string, rtmps_port: int, tls_certificate_path: string, tls_private_key_path: string},
     *     stream_key_configured: bool,
     *     configuration_revision: string
     * }
     */
    public function statusState(): array
    {
        $snapshot = $this->managedSnapshot() ?? $this->configuredSnapshot();

        return [
            'configuration' => $this->publicConfiguration($snapshot),
            'stream_key_configured' => $snapshot['stream_key_configured'],
            'configuration_revision' => $this->digest($snapshot),
        ];
    }

    /**
     * @param  array{
     *     enabled: bool,
     *     public_host: string,
     *     rtmps_bind_address: string,
     *     rtmps_port: int,
     *     tls_certificate_path: string,
     *     tls_private_key_path: string
     * }  $data
     * @return array{
     *     outcome: string,
     *     request_id: string|null,
     *     key_created?: bool,
     *     configuration_changed?: bool,
     *     configuration?: array{
     *         enabled: bool,
     *         public_host: string,
     *         rtmps_bind_address: string,
     *         rtmps_port: int,
     *         tls_certificate_path: string,
     *         tls_private_key_path: string
     *     }
     * }
     */
    public function update(
        array $data,
        string $configurationRevision,
        User $actor,
        Request $request,
    ): array {
        $before = $this->managedSnapshot();
        if ($before === null) {
            return $this->configurationFailed($actor, $request, null, 'managed_configuration_unavailable');
        }
        if (! hash_equals($this->digest($before), $configurationRevision)) {
            return $this->configurationFailed($actor, $request, null, 'configuration_changed');
        }

        $desired = [
            'enabled' => $data['enabled'],
            'public_host' => strtolower($data['public_host']),
            'bind_address' => $data['rtmps_bind_address'],
            'rtmps_port' => $data['rtmps_port'],
            'tls_certificate_path' => $data['tls_certificate_path'],
            'tls_private_key_path' => $data['tls_private_key_path'],
        ];
        $configurationChanged = $this->configurationFields($before) !== $desired;
        $changedFields = [];
        foreach (array_keys($desired) as $field) {
            if ($before[$field] !== $desired[$field]) {
                $changedFields[] = $field === 'bind_address' ? 'rtmps_bind_address' : $field;
            }
        }

        if (! $configurationChanged && (! $before['enabled'] || $before['stream_key_configured'])) {
            $this->auditService->record(
                'wallboard.live_stream.configuration_update_skipped',
                'wallboard-live-stream',
                $actor,
                ['reason' => 'unchanged'],
                request: $request,
            );

            return [
                'outcome' => 'succeeded',
                'request_id' => null,
                'key_created' => false,
                'configuration_changed' => false,
                'configuration' => $this->publicConfiguration($before),
            ];
        }

        $this->auditService->record(
            'wallboard.live_stream.configuration_update_requested',
            'wallboard-live-stream',
            $actor,
            [
                'changed_fields' => $changedFields,
                'enabling' => $desired['enabled'],
            ],
            request: $request,
        );

        $result = $this->requests->configure(
            $desired,
            $this->digest($before),
            (string) $actor->getAuthIdentifier(),
        );
        $after = $this->managedSnapshot();
        $requestId = $result['request_id'];

        if ($result['outcome'] === 'succeeded'
            && $result['exit_code'] === 0
            && $after !== null
            && $this->configurationFields($after) === $desired
            && (! $after['enabled'] || $after['stream_key_configured'])
            && is_bool($result['key_created'] ?? null)
            && is_string($result['config_sha256'] ?? null)
            && hash_equals($this->digest($after), $result['config_sha256'])
            && $result['key_created'] === (! $before['stream_key_configured'] && $after['stream_key_configured'])) {
            try {
                $this->auditService->record(
                    'wallboard.live_stream.configuration_updated',
                    'wallboard-live-stream',
                    $actor,
                    [
                        'configuration_request_id' => $requestId,
                        'changed_fields' => $changedFields,
                        'enabled' => $after['enabled'],
                        'key_created' => $result['key_created'],
                    ],
                    request: $request,
                );
            } catch (Throwable $exception) {
                // The root worker already durably activated and verified this
                // configuration. Do not turn that committed change into an
                // ambiguous error response solely because the final audit failed.
                report($exception);
                Log::critical('Wallboard live-stream configuration completed without its application audit record.', [
                    'action' => 'wallboard.live_stream.configuration_updated',
                    'actor_id' => (string) $actor->getAuthIdentifier(),
                    'configuration_request_id' => $requestId,
                ]);
            }

            return [
                'outcome' => 'succeeded',
                'request_id' => $requestId,
                'key_created' => $result['key_created'],
                'configuration_changed' => $configurationChanged,
                'configuration' => $this->publicConfiguration($after),
            ];
        }

        $changedByAnotherRequest = $result['outcome'] === 'conflict'
            || ($after !== null
                && ! hash_equals($this->digest($before), $this->digest($after))
                && $this->configurationFields($after) !== $desired);
        $reason = $changedByAnotherRequest
            ? 'configuration_changed'
            : ($result['outcome'] === 'succeeded' ? 'managed_configuration_verification_failed' : $result['outcome']);

        return $this->configurationFailed($actor, $request, $requestId, $reason);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     public_host: string,
     *     bind_address: string,
     *     rtmps_port: int,
     *     tls_certificate_path: string,
     *     tls_private_key_path: string,
     *     stream_key_configured: bool
     * }|null
     */
    private function managedSnapshot(): ?array
    {
        $path = trim((string) config('wallboard_live_stream.managed_env_path', ''));
        if ($path !== '') {
            // The privileged worker atomically replaces this file while the
            // current PHP request is waiting. Do not verify the committed
            // state against metadata cached before that replacement.
            clearstatcache(true, $path);
        }
        if ($path === '' || is_link($path) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $size = @filesize($path);
        if (! is_int($size) || $size < 1 || $size > self::MAX_ENV_BYTES) {
            return null;
        }
        $contents = @file_get_contents($path);
        if (! is_string($contents) || strlen($contents) !== $size || str_contains($contents, "\0")) {
            return null;
        }

        $values = array_fill_keys(array_keys(self::ENV_KEYS), []);
        $lookup = array_flip(self::ENV_KEYS);
        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }
            $name = substr($line, 0, $separator);
            if (isset($lookup[$name])) {
                $values[$lookup[$name]][] = substr($line, $separator + 1);
            }
        }
        foreach ($values as $matches) {
            if (count($matches) > 1) {
                return null;
            }
        }

        $enabledValue = strtolower($values['enabled'][0] ?? 'false');
        if (! in_array($enabledValue, ['true', 'false'], true)) {
            return null;
        }
        $publicHost = $values['public_host'][0] ?? '';
        $bindAddress = $values['bind_address'][0] ?? '0.0.0.0';
        $portValue = $values['rtmps_port'][0] ?? '1936';
        $certificatePath = $values['tls_certificate_path'][0] ?? '';
        $privateKeyPath = $values['tls_private_key_path'][0] ?? '';
        foreach ([$publicHost, $bindAddress, $certificatePath, $privateKeyPath] as $value) {
            if (strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return null;
            }
        }
        if (preg_match('/\A[0-9]{1,10}\z/D', $portValue) !== 1
            || ($publicHost !== '' && ! $this->process->isValidPublicHost($publicHost))
            || ! $this->process->isValidBindAddress($bindAddress)
            || ! $this->process->isValidRtmpsPort((int) $portValue)
            || ($certificatePath !== '' && ! $this->process->isValidManagedTlsPath($certificatePath))
            || ($privateKeyPath !== '' && ! $this->process->isValidManagedTlsPath($privateKeyPath))
            || ($enabledValue === 'true'
                && ($publicHost === '' || $certificatePath === '' || $privateKeyPath === ''))) {
            return null;
        }

        $streamKeyConfigured = false;
        if (isset($values['stream_key'][0])) {
            if ($values['stream_key'][0] !== '' && ! $this->process->isValidStreamKey($values['stream_key'][0])) {
                return null;
            }
            $streamKeyConfigured = $values['stream_key'][0] !== '';
        }

        return [
            'enabled' => $enabledValue === 'true',
            'public_host' => strtolower($publicHost),
            'bind_address' => $bindAddress,
            'rtmps_port' => (int) $portValue,
            'tls_certificate_path' => $certificatePath,
            'tls_private_key_path' => $privateKeyPath,
            'stream_key_configured' => $streamKeyConfigured,
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     public_host: string,
     *     bind_address: string,
     *     rtmps_port: int,
     *     tls_certificate_path: string,
     *     tls_private_key_path: string,
     *     stream_key_configured: bool
     * }
     */
    private function configuredSnapshot(): array
    {
        $streamKey = (string) config('wallboard_live_stream.stream_key', '');

        return [
            'enabled' => (bool) config('wallboard_live_stream.enabled', false),
            'public_host' => strtolower(trim((string) config('wallboard_live_stream.public_host', ''))),
            'bind_address' => trim((string) config('wallboard_live_stream.rtmps_bind_address', '0.0.0.0')),
            'rtmps_port' => (int) config('wallboard_live_stream.rtmps_port', 1936),
            'tls_certificate_path' => trim((string) config('wallboard_live_stream.tls_certificate_path', '')),
            'tls_private_key_path' => trim((string) config('wallboard_live_stream.tls_private_key_path', '')),
            'stream_key_configured' => $this->process->isValidStreamKey($streamKey),
        ];
    }

    /** @param array<string, bool|int|string> $snapshot */
    private function digest(array $snapshot): string
    {
        try {
            $canonical = json_encode([
                'enabled' => $snapshot['enabled'],
                'public_host' => $snapshot['public_host'],
                'bind_address' => $snapshot['bind_address'],
                'rtmps_port' => $snapshot['rtmps_port'],
                'tls_certificate_path' => $snapshot['tls_certificate_path'],
                'tls_private_key_path' => $snapshot['tls_private_key_path'],
                'stream_key_configured' => $snapshot['stream_key_configured'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('The wallboard live-stream configuration could not be versioned.', previous: $exception);
        }

        return hash('sha256', $canonical);
    }

    /**
     * @param  array<string, bool|int|string>  $snapshot
     * @return array{enabled: bool, public_host: string, bind_address: string, rtmps_port: int, tls_certificate_path: string, tls_private_key_path: string}
     */
    private function configurationFields(array $snapshot): array
    {
        return [
            'enabled' => $snapshot['enabled'],
            'public_host' => $snapshot['public_host'],
            'bind_address' => $snapshot['bind_address'],
            'rtmps_port' => $snapshot['rtmps_port'],
            'tls_certificate_path' => $snapshot['tls_certificate_path'],
            'tls_private_key_path' => $snapshot['tls_private_key_path'],
        ];
    }

    /**
     * @param  array<string, bool|int|string>  $snapshot
     * @return array{enabled: bool, public_host: string, rtmps_bind_address: string, rtmps_port: int, tls_certificate_path: string, tls_private_key_path: string}
     */
    private function publicConfiguration(array $snapshot): array
    {
        return [
            'enabled' => $snapshot['enabled'],
            'public_host' => $snapshot['public_host'],
            'rtmps_bind_address' => $snapshot['bind_address'],
            'rtmps_port' => $snapshot['rtmps_port'],
            'tls_certificate_path' => $snapshot['tls_certificate_path'],
            'tls_private_key_path' => $snapshot['tls_private_key_path'],
        ];
    }

    /** @return array{outcome: string, request_id: string|null} */
    private function configurationFailed(
        User $actor,
        Request $request,
        ?string $configurationRequestId,
        string $reason,
    ): array {
        $this->auditService->record(
            'wallboard.live_stream.configuration_update_failed',
            'wallboard-live-stream',
            $actor,
            array_filter([
                'configuration_request_id' => $configurationRequestId,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null),
            request: $request,
        );

        return [
            'outcome' => $reason,
            'request_id' => $configurationRequestId,
        ];
    }
}
