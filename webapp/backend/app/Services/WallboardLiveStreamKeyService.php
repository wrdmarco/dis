<?php

namespace App\Services;

use App\Models\User;
use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class WallboardLiveStreamKeyService
{
    private const ENV_KEY = 'WALLBOARD_LIVE_STREAM_STREAM_KEY';

    private const MAX_ENV_BYTES = 2_097_152;

    public function __construct(
        private readonly WallboardLiveStreamProcessService $process,
        private readonly WallboardLiveStreamKeyRequestService $requests,
        private readonly AuditService $auditService,
    ) {}

    /** @return array{stream_key: string, stream_key_version: string}|null */
    public function reveal(User $actor, Request $request): ?array
    {
        $streamKey = $this->currentStreamKey();
        if ($streamKey === null) {
            $this->auditService->record(
                'wallboard.live_stream.key_reveal_failed',
                'wallboard-live-stream',
                $actor,
                ['reason' => 'managed_key_unavailable'],
                request: $request,
            );

            return null;
        }

        $version = $this->versionFor($streamKey);
        $this->auditService->record(
            'wallboard.live_stream.key_revealed',
            'wallboard-live-stream',
            $actor,
            request: $request,
        );

        return [
            'stream_key' => $streamKey,
            'stream_key_version' => $version,
        ];
    }

    public function streamKeyVersion(): ?string
    {
        $streamKey = $this->currentStreamKey();

        return $streamKey === null ? null : $this->versionFor($streamKey);
    }

    /**
     * @return array{
     *     outcome: string,
     *     request_id: string|null,
     *     stream_key?: string,
     *     stream_key_version?: string,
     *     rotated_at?: string,
     *     previous_key_revoked?: bool,
     *     obs_reconnect_required?: bool
     * }
     */
    public function rotate(User $actor, Request $request): array
    {
        $currentKey = $this->currentStreamKey();
        if ($currentKey === null) {
            return $this->rotationFailed($actor, $request, null, 'managed_key_unavailable');
        }

        $expectedKeySha256 = hash('sha256', $currentKey);
        $newKey = $this->generateKey($currentKey);
        $this->auditService->record(
            'wallboard.live_stream.key_rotation_requested',
            'wallboard-live-stream',
            $actor,
            request: $request,
        );

        $result = $this->requests->rotate(
            $newKey,
            $expectedKeySha256,
            (string) $actor->getAuthIdentifier(),
        );
        $activeKey = $this->currentStreamKey();

        if ($result['exit_code'] === 0
            && $result['outcome'] === 'succeeded'
            && is_string($activeKey)
            && hash_equals($newKey, $activeKey)) {
            $rotatedAt = ApiDateTime::now();
            try {
                $this->auditService->record(
                    'wallboard.live_stream.key_rotated',
                    'wallboard-live-stream',
                    $actor,
                    ['rotation_request_id' => $result['request_id']],
                    request: $request,
                );
            } catch (Throwable $exception) {
                // The root worker has already activated and verified the new key. A
                // response failure here would strand the operator without that key.
                report($exception);
                Log::critical('Wallboard live-stream key rotation completed without its application audit record.', [
                    'action' => 'wallboard.live_stream.key_rotated',
                    'actor_id' => (string) $actor->getAuthIdentifier(),
                    'rotation_request_id' => $result['request_id'],
                ]);
            }

            return [
                'outcome' => 'succeeded',
                'request_id' => $result['request_id'],
                'stream_key' => $newKey,
                'stream_key_version' => $this->versionFor($newKey),
                'rotated_at' => $rotatedAt,
                'previous_key_revoked' => true,
                'obs_reconnect_required' => true,
            ];
        }

        $changedByAnotherRequest = $result['outcome'] === 'conflict'
            || (is_string($activeKey)
                && ! hash_equals($expectedKeySha256, hash('sha256', $activeKey))
                && ! hash_equals($newKey, $activeKey));
        $reason = $changedByAnotherRequest
            ? 'key_changed'
            : ($result['outcome'] === 'succeeded' ? 'managed_key_verification_failed' : $result['outcome']);

        return $this->rotationFailed($actor, $request, $result['request_id'], $reason);
    }

    private function currentStreamKey(): ?string
    {
        $path = trim((string) config('wallboard_live_stream.managed_env_path', ''));
        if ($path !== '') {
            // Rotation replaces the managed environment atomically from a
            // separate root process, so discard metadata cached earlier in
            // this request before checking and reading it again.
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

        $matches = [];
        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
            if (str_starts_with($line, self::ENV_KEY.'=')) {
                $matches[] = substr($line, strlen(self::ENV_KEY) + 1);
            }
        }
        if (count($matches) !== 1 || ! $this->process->isValidStreamKey($matches[0])) {
            return null;
        }

        return $matches[0];
    }

    private function generateKey(string $currentKey): string
    {
        do {
            $candidate = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        } while (hash_equals($currentKey, $candidate));

        if (strlen($candidate) !== 64 || ! $this->process->isValidStreamKey($candidate)) {
            throw new RuntimeException('A valid wallboard live-stream key could not be generated.');
        }

        return $candidate;
    }

    private function versionFor(string $streamKey): string
    {
        $applicationKey = (string) config('app.key', '');
        if ($applicationKey === '') {
            throw new RuntimeException('The application key is unavailable for stream-key versioning.');
        }

        return hash_hmac('sha256', $streamKey, $applicationKey);
    }

    /** @return array{outcome: string, request_id: string|null} */
    private function rotationFailed(
        User $actor,
        Request $request,
        ?string $rotationRequestId,
        string $reason,
    ): array {
        $this->auditService->record(
            'wallboard.live_stream.key_rotation_failed',
            'wallboard-live-stream',
            $actor,
            array_filter([
                'rotation_request_id' => $rotationRequestId,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null),
            request: $request,
        );

        return [
            'outcome' => $reason,
            'request_id' => $rotationRequestId,
        ];
    }
}
