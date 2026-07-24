<?php

namespace App\Jobs;

use App\Contracts\PushProvider;
use App\Exceptions\TransientPushDeliveryException;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\PushDeliveryLog;
use App\Models\User;
use App\Services\DispatchPushOutboxService;
use App\Services\FcmTokenIdentityLock;
use App\Services\MobileDeviceSessionService;
use App\Services\StatusService;
use App\Support\PushNotificationIdentity;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SendFcmNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    private const DELIVERY_ORDER_LOCK_SECONDS = FcmTokenIdentityLock::LOCK_SECONDS;

    private const DELIVERY_ORDER_WAIT_SECONDS = 30;

    public int $tries = 4;

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public readonly string $fcmTokenId,
        public readonly string $messageType,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $dispatchRequestId = null,
        public readonly ?string $dispatchPushOutboxId = null,
        public readonly ?string $expectedRevocationGeneration = null,
    ) {
        $this->onConnection('push')->onQueue('push');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(
        PushProvider $client,
        DispatchPushOutboxService $outbox,
        ?FcmTokenIdentityLock $tokenIdentityLock = null,
        ?MobileDeviceSessionService $mobileSessions = null,
    ): void {
        $tokenIdentityLock ??= app(FcmTokenIdentityLock::class);
        $mobileSessions ??= app(MobileDeviceSessionService::class);

        if ($this->dispatchPushOutboxId !== null) {
            $outbox->markProcessing($this->dispatchPushOutboxId, $this->fcmTokenId);
        }

        try {
            if (! $this->requiresOrderedOperationalDelivery()) {
                $this->deliverWithTokenLock($client, $outbox, $tokenIdentityLock, $mobileSessions);

                return;
            }

            $lockKey = PushNotificationIdentity::deliveryOrderLockKey(
                $this->data,
                $this->fcmTokenId,
                $this->dispatchRequestId,
            );
            if ($lockKey === null) {
                $this->cancelStaleOutbox($outbox);

                return;
            }

            // Every phase for one incident and device uses the same distributed
            // lock. A preannouncement that is already in flight finishes before a
            // later alarm is submitted to the provider; once the
            // later database state is committed, a delayed old job is discarded.
            // No database lock is held during the provider request.
            Cache::lock($lockKey, self::DELIVERY_ORDER_LOCK_SECONDS)->block(
                self::DELIVERY_ORDER_WAIT_SECONDS,
                function () use ($client, $outbox, $tokenIdentityLock, $mobileSessions): void {
                    if (! $this->isCurrentOperationalPhase()) {
                        $this->cancelStaleOutbox($outbox);

                        return;
                    }

                    $this->deliverWithTokenLock($client, $outbox, $tokenIdentityLock, $mobileSessions);
                },
            );
        } catch (Throwable $exception) {
            if ($this->dispatchPushOutboxId !== null) {
                $outbox->markQueueRetry(
                    $this->dispatchPushOutboxId,
                    $this->fcmTokenId,
                    $this->retryDelaySeconds(),
                );
            }

            throw $exception;
        }
    }

    private function deliverWithTokenLock(
        PushProvider $client,
        DispatchPushOutboxService $outbox,
        FcmTokenIdentityLock $tokenIdentityLock,
        MobileDeviceSessionService $mobileSessions,
    ): void {
        $token = FcmToken::query()->find($this->fcmTokenId);
        if ($token === null) {
            $this->deliver($client, $outbox, $mobileSessions);

            return;
        }

        $lockedIdentityKey = FcmTokenIdentityLock::keyForToken($token);
        $lockedProviderTokenKey = FcmTokenIdentityLock::providerTokenKeyForToken($token);
        $tokenIdentityLock->synchronizedMany(
            [[
                'user_id' => (string) $token->user_id,
                'device_id' => (string) $token->device_id,
                'client_type' => (string) $token->client_type,
            ]],
            function () use ($client, $outbox, $mobileSessions, $lockedIdentityKey, $lockedProviderTokenKey): void {
                $freshToken = FcmToken::query()->find($this->fcmTokenId);
                if ($freshToken !== null
                    && (! hash_equals($lockedIdentityKey, FcmTokenIdentityLock::keyForToken($freshToken))
                        || ! hash_equals(
                            $lockedProviderTokenKey,
                            FcmTokenIdentityLock::providerTokenKeyForToken($freshToken),
                        ))) {
                    throw TransientPushDeliveryException::forDeviceIdentityChange();
                }

                // deliver() reloads the row once more while both the device
                // identity and provider-token locks are held. The state check,
                // provider request and conditional invalidation therefore form
                // one protected transition.
                $this->deliver($client, $outbox, $mobileSessions);
            },
            [[
                'platform' => (string) $token->platform,
                'token_hash' => FcmTokenIdentityLock::tokenHash($token),
            ]],
            [(string) $token->user_id],
        );
    }

    private function retryDelaySeconds(): int
    {
        $attempt = max(1, $this->attempts());
        $backoff = $this->backoff();

        return $backoff[min(count($backoff) - 1, $attempt - 1)] ?? 30;
    }

    private function deliver(
        PushProvider $client,
        DispatchPushOutboxService $outbox,
        MobileDeviceSessionService $mobileSessions,
    ): void {
        $token = FcmToken::query()->find($this->fcmTokenId);
        $revocationMessage = $this->messageType === 'session_revoked';
        $revocationDelivery = $this->isRevocationDelivery($token);
        if ($token === null
            || ($revocationMessage && ! $revocationDelivery)
            || (! $revocationMessage && ! $token->is_active)) {
            if ($this->dispatchPushOutboxId !== null) {
                $outbox->markTerminal($this->dispatchPushOutboxId, $this->fcmTokenId, 'token_inactive');
            }

            return;
        }

        $user = $token->user;
        if (! $revocationMessage
            && ($user === null
                || $mobileSessions->liveTokenForClient(
                    $user,
                    $token->personal_access_token_id,
                    (string) $token->client_type,
                ) === null)) {
            $this->deactivateUnchangedToken($token);
            if ($this->dispatchPushOutboxId !== null) {
                $outbox->markTerminal(
                    $this->dispatchPushOutboxId,
                    $this->fcmTokenId,
                    'token_session_invalid',
                );
            }

            return;
        }

        try {
            $response = $client->send(
                $token,
                $this->title,
                $this->body,
                $this->deliveryData($token),
            );
        } catch (Throwable $exception) {
            $this->recordDelivery($token, 'failed', null, 'delivery_exception');
            report($exception);

            throw $exception;
        }

        $payload = $response->json();
        $status = $response->successful() ? 'sent' : 'failed';
        $errorCode = $response->successful() ? null : $this->providerErrorCode($token, $payload, $response->status());
        $providerMessageId = $response->successful()
            ? ((string) ($payload['name'] ?? $response->header('apns-id') ?: '')) ?: null
            : null;

        // Persist exactly one diagnostic row for this queue attempt. A
        // transient response is then rethrown outside the transport catch, so
        // it cannot create a second delivery log for the same attempt.
        $this->recordDelivery($token, $status, $providerMessageId, $errorCode);

        if ($this->isTransientHttpStatus($response->status())) {
            // Never revoke a token based on a temporary provider response. The
            // bounded queue backoff retries delivery without exposing the
            // provider response body or credentials in the exception.
            throw TransientPushDeliveryException::forHttpStatus($response->status());
        }

        if (in_array($errorCode, ['NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED', 'BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
            $this->invalidateUnchangedProviderToken($token);
        }

        if ($this->dispatchPushOutboxId !== null) {
            if ($response->successful()) {
                $outbox->markDelivered($this->dispatchPushOutboxId, $this->fcmTokenId);
            } else {
                $outbox->markTerminal($this->dispatchPushOutboxId, $this->fcmTokenId, 'provider_rejected');
            }
        }

        if ($revocationDelivery) {
            $this->completeRevokedTokenGeneration();
        }
    }

    private function isPreannouncement(): bool
    {
        $type = $this->data['type'] ?? null;

        return $this->messageType === 'incident_preannouncement'
            || $type === 'incident_preannouncement'
            || ($type === 'dispatch_update' && ($this->data['action_mode'] ?? null) === 'availability');
    }

    private function isDefinitiveAlarm(): bool
    {
        $type = $this->data['type'] ?? null;
        $actionMode = $this->data['action_mode'] ?? null;

        return $type === 'dispatch_request'
            || $actionMode === 'attendance'
            || $actionMode === 'test_ack';
    }

    private function isResponseSync(): bool
    {
        return $this->messageType === 'dispatch_response_sync'
            || ($this->data['type'] ?? null) === 'dispatch_response_sync';
    }

    private function requiresOrderedOperationalDelivery(): bool
    {
        return $this->isPreannouncement()
            || $this->isDefinitiveAlarm();
    }

    private function isCurrentOperationalPhase(): bool
    {
        if ($this->dispatchRequestId === null || ! Str::isUlid($this->dispatchRequestId)) {
            return false;
        }

        if ($this->dispatchPushOutboxId !== null && ! DispatchPushOutbox::query()
            ->whereKey($this->dispatchPushOutboxId)
            ->where('dispatch_request_id', $this->dispatchRequestId)
            ->where('fcm_token_id', $this->fcmTokenId)
            ->whereNull('delivered_at')
            ->whereNull('cancelled_at')
            ->exists()) {
            return false;
        }

        $recipientUserId = FcmToken::query()
            ->whereKey($this->fcmTokenId)
            ->where('is_active', true)
            ->value('user_id');
        if ($recipientUserId === null) {
            return false;
        }

        $query = DispatchRequest::query()
            ->whereKey($this->dispatchRequestId)
            ->whereHas('incident', function ($incident): void {
                if ($this->isPreannouncement()) {
                    $incident->where('status', 'active');

                    return;
                }
                $incident->whereNotIn('status', ['resolved', 'cancelled']);
            });

        if ($this->isPreannouncement()) {
            return $query
                ->where('status', 'draft')
                ->whereHas('recipients', fn ($recipients) => $recipients
                    ->where('user_id', $recipientUserId)
                    ->where('response_status', 'pending'))
                ->exists();
        }

        if ($this->isResponseSync()) {
            $response = $this->data['response'] ?? null;
            if (! in_array($response, ['accepted', 'declined'], true)) {
                return false;
            }

            return $query
                ->whereIn('status', ['sent', 'escalated'])
                ->whereHas('recipients', fn ($recipients) => $recipients
                    ->where('user_id', $recipientUserId)
                    ->where('response_status', $response))
                ->exists();
        }

        return $query
            ->whereIn('status', ['sent', 'escalated'])
            ->whereHas('recipients', fn ($recipients) => $recipients
                ->where('user_id', $recipientUserId)
                ->where('response_status', 'pending'))
            ->exists();
    }

    private function cancelStaleOutbox(DispatchPushOutboxService $outbox): void
    {
        if ($this->dispatchPushOutboxId === null) {
            return;
        }

        $outbox->markTerminal(
            $this->dispatchPushOutboxId,
            $this->fcmTokenId,
            'stale_dispatch_phase',
        );
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->dispatchPushOutboxId === null) {
            return;
        }

        try {
            // Exhausting one bounded Laravel retry cycle returns the durable
            // outbox row to pending with a longer bounded backoff. The
            // scheduler then retries it, or cancels it if the incident closed.
            app(DispatchPushOutboxService::class)->releaseAfterDeliveryFailure(
                $this->dispatchPushOutboxId,
                $this->fcmTokenId,
            );
        } catch (Throwable $releaseException) {
            // A stale queued lease is also reclaimed by the scheduler. Never
            // include provider responses, tokens or credentials in this report.
            report($releaseException);
        }
    }

    private function isRevocationDelivery(?FcmToken $token): bool
    {
        return $token !== null
            && ! $token->is_active
            && $token->revoked_at !== null
            && $this->messageType === 'session_revoked'
            && ($this->data['type'] ?? null) === 'session_revoked'
            && $this->expectedRevocationGeneration !== null
            && hash_equals(
                (string) $token->revocation_generation,
                $this->expectedRevocationGeneration,
            );
    }

    private function completeRevokedTokenGeneration(): void
    {
        if ($this->expectedRevocationGeneration === null) {
            return;
        }

        FcmToken::query()
            ->whereKey($this->fcmTokenId)
            ->where('is_active', false)
            ->whereNotNull('revoked_at')
            ->where('revocation_generation', $this->expectedRevocationGeneration)
            ->update([
                'revocation_generation' => null,
                'updated_at' => now(),
            ]);
    }

    /** @return array<string, string> */
    private function deliveryData(FcmToken $token): array
    {
        $data = $this->data;
        unset($data['session_token_id']);

        $sessionTokenId = trim((string) $token->personal_access_token_id);
        if (preg_match('/^[A-Za-z0-9]{1,64}$/', $sessionTokenId) === 1) {
            $data['session_token_id'] = $sessionTokenId;
        }

        return $data;
    }

    private function invalidateUnchangedProviderToken(FcmToken $token): void
    {
        $this->deactivateUnchangedToken($token);
    }

    private function deactivateUnchangedToken(FcmToken $snapshot): bool
    {
        return DB::transaction(function () use ($snapshot): bool {
            $user = User::query()
                ->whereKey($snapshot->user_id)
                ->lockForUpdate()
                ->first();
            $query = FcmToken::query()
                ->whereKey($snapshot->id)
                ->where('user_id', $snapshot->user_id)
                ->where('device_id', $snapshot->device_id)
                ->where('client_type', $snapshot->client_type)
                ->where('platform', $snapshot->platform)
                ->where('is_active', $snapshot->is_active);

            if ($snapshot->token_hash === null) {
                $query->whereNull('token_hash')->where('token', $snapshot->token);
            } else {
                $query->where('token_hash', $snapshot->token_hash);
            }

            if ($snapshot->personal_access_token_id === null) {
                $query->whereNull('personal_access_token_id');
            } else {
                $query->where('personal_access_token_id', $snapshot->personal_access_token_id);
            }

            $token = $query->lockForUpdate()->first();
            if ($token === null || ! $snapshot->is_active) {
                return false;
            }

            $token->forceFill([
                'is_active' => false,
                'revoked_at' => now(),
                'revocation_generation' => null,
            ])->save();

            if ($user !== null && ! $user->fcmTokens()
                ->where('client_type', 'operator')
                ->where('is_active', true)
                ->exists()) {
                $user->update(['push_enabled' => false]);
                app(StatusService::class)->enforcePushUnavailable($user);
            }

            return true;
        });
    }

    private function isTransientHttpStatus(int $status): bool
    {
        return in_array($status, [408, 429], true) || $status >= 500;
    }

    private function recordDelivery(FcmToken $token, string $status, ?string $providerMessageId, ?string $errorCode): void
    {
        PushDeliveryLog::query()->create([
            'user_id' => $token->user_id,
            'fcm_token_id' => $token->id,
            'dispatch_request_id' => $this->dispatchRequestId,
            'message_type' => $this->messageType,
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'error_code' => $errorCode,
            'sent_at' => now(),
        ]);
    }

    private function providerErrorCode(FcmToken $token, mixed $payload, int $httpStatus): string
    {
        if (strtolower((string) $token->platform) === 'ios') {
            return is_array($payload) && is_string($payload['reason'] ?? null)
                ? $payload['reason']
                : 'apns_http_'.max(100, min(599, $httpStatus));
        }

        $candidates = [];
        if (is_array($payload) && is_array($payload['error'] ?? null)) {
            $details = $payload['error']['details'] ?? [];
            if (is_array($details)) {
                foreach ($details as $detail) {
                    if (is_array($detail)) {
                        $candidates[] = $detail['errorCode'] ?? null;
                    }
                }
            }

            $candidates[] = $payload['error']['status'] ?? null;
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return 'fcm_http_'.max(100, min(599, $httpStatus));
    }
}
