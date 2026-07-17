<?php

namespace App\Jobs;

use App\Contracts\PushProvider;
use App\Exceptions\TransientPushDeliveryException;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\PushDeliveryLog;
use App\Services\DispatchPushOutboxService;
use App\Support\PushNotificationIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class SendFcmNotification implements ShouldQueue
{
    use Queueable;

    private const DELIVERY_ORDER_LOCK_SECONDS = 45;

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
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(PushProvider $client, DispatchPushOutboxService $outbox): void
    {
        if (! $this->requiresOrderedOperationalDelivery()) {
            $this->deliver($client, $outbox);

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
            function () use ($client, $outbox): void {
                if (! $this->isCurrentOperationalPhase()) {
                    $this->cancelStaleOutbox($outbox);

                    return;
                }

                $this->deliver($client, $outbox);
            },
        );
    }

    private function deliver(PushProvider $client, DispatchPushOutboxService $outbox): void
    {
        $token = FcmToken::query()->find($this->fcmTokenId);
        if ($token === null || ! $token->is_active) {
            if ($this->dispatchPushOutboxId !== null) {
                $outbox->markTerminal($this->dispatchPushOutboxId, $this->fcmTokenId, 'token_inactive');
            }

            return;
        }

        try {
            $response = $client->send($token, $this->title, $this->body, $this->data);
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
            $token->update(['is_active' => false, 'revoked_at' => now()]);
            if (! $token->user?->fcmTokens()->where('is_active', true)->exists()) {
                $token->user?->update(['push_enabled' => false]);
            }
        }

        if ($this->dispatchPushOutboxId !== null) {
            if ($response->successful()) {
                $outbox->markDelivered($this->dispatchPushOutboxId, $this->fcmTokenId);
            } else {
                $outbox->markTerminal($this->dispatchPushOutboxId, $this->fcmTokenId, 'provider_rejected');
            }
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
            return $query->where('status', 'draft')->exists();
        }

        return $query->whereIn('status', ['sent', 'escalated'])->exists();
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
