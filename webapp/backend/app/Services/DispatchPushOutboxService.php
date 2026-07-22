<?php

namespace App\Services;

use App\Contracts\DispatchNotificationQueue;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Support\ApiDateTime;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchPushOutboxService
{
    private const QUEUE_LEASE_MINUTES = 15;

    public function __construct(private readonly DispatchNotificationQueue $queue) {}

    /**
     * Store an alarm notification in the same database transaction as the
     * dispatch transition. Only database identifiers are retained; provider
     * tokens and other credentials never enter the outbox. Delivery is
     * intentionally at-least-once: an ambiguous crash after queue acceptance
     * can enqueue a duplicate, while losing an alarm is not acceptable.
     *
     * @param  array<string, string>  $data
     */
    public function store(
        string $dispatchRequestId,
        string $fcmTokenId,
        string $messageType,
        string $title,
        string $body,
        array $data,
        ?\DateTimeInterface $availableAt = null,
        ?string $releaseReason = null,
    ): DispatchPushOutbox {
        $deduplicationKey = hash('sha256', implode('|', [
            $dispatchRequestId,
            $fcmTokenId,
            $messageType,
        ]));

        return DispatchPushOutbox::query()->firstOrCreate(
            ['deduplication_key' => $deduplicationKey],
            [
                'dispatch_request_id' => $dispatchRequestId,
                'fcm_token_id' => $fcmTokenId,
                'message_type' => $messageType,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'available_at' => $availableAt ?? now(),
                'release_reason' => $releaseReason ?? 'immediate',
            ],
        );
    }

    /**
     * @return array{queued: int, failed: int, cancelled: int}
     */
    public function flushPending(int $limit = 100, ?string $dispatchRequestId = null): array
    {
        $limit = max(1, min(500, $limit));
        $staleQueuedAt = now()->subMinutes(self::QUEUE_LEASE_MINUTES);
        $ids = DispatchPushOutbox::query()
            ->whereNull('delivered_at')
            ->whereNull('cancelled_at')
            ->where('available_at', '<=', now())
            ->where(fn ($query) => $query
                ->whereNull('queued_at')
                ->orWhere('queued_at', '<=', $staleQueuedAt))
            ->when($dispatchRequestId !== null, fn ($query) => $query->where('dispatch_request_id', $dispatchRequestId))
            ->oldest()
            ->limit($limit)
            ->pluck('id');

        $result = ['queued' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($ids as $id) {
            $outcome = $this->enqueueOne((string) $id);
            if ($outcome !== null) {
                $result[$outcome]++;
            }
            // A queue connection failure is infrastructure-wide in practice.
            // Stop this run instead of repeating the same timeout and log for
            // every alarm device; the scheduler will resume after backoff.
            if ($outcome === 'failed') {
                break;
            }
        }

        return $result;
    }

    private function enqueueOne(string $id): ?string
    {
        $claim = $this->withLockedHierarchy($id, function (
            DispatchPushOutbox $notification,
            DispatchRequest $dispatch,
            Incident $incident,
        ): ?array {
            $clockNow = ApiDateTime::comparableWallClock(now());
            $staleQueuedAt = $clockNow->subMinutes(self::QUEUE_LEASE_MINUTES);
            // DIS historically persists application wall-clock values through
            // a UTC PostgreSQL session. Eloquent therefore hydrates the same
            // clock value with a UTC offset, which makes it appear two hours in
            // the future during Dutch summer time. Normalize before comparing;
            // otherwise an immediately available alarm is silently skipped.
            $availableAt = $notification->available_at !== null
                ? ApiDateTime::comparableWallClock($notification->available_at)
                : null;
            $queuedAt = $notification->queued_at !== null
                ? ApiDateTime::comparableWallClock($notification->queued_at)
                : null;
            if ($notification->delivered_at !== null
                || $notification->cancelled_at !== null
                || ($availableAt !== null && $availableAt->greaterThan($clockNow))
                || ($queuedAt !== null && $queuedAt->greaterThan($staleQueuedAt))) {
                return null;
            }
            if (! $this->isDeliverablePhase($notification, $dispatch, $incident)) {
                $notification->forceFill([
                    'cancelled_at' => now(),
                    'last_error_code' => 'dispatch_not_deliverable',
                ])->save();

                return ['outcome' => 'cancelled'];
            }

            $claimedAt = now();
            $notification->forceFill([
                'queued_at' => $claimedAt,
                'last_attempted_at' => $claimedAt,
                'last_error_code' => null,
            ])->save();
            if ((string) $notification->message_type === 'dispatch_request') {
                $dispatch->forceFill([
                    'send_status' => 'queued_for_push',
                    'send_released_at' => $dispatch->send_released_at ?? $claimedAt,
                ])->save();
            }

            return [
                'outcome' => 'claimed',
                'notification' => $notification->withoutRelations(),
            ];
        });

        if ($claim === null) {
            return null;
        }
        if (($claim['outcome'] ?? null) === 'cancelled') {
            return 'cancelled';
        }

        /** @var DispatchPushOutbox|null $notification */
        $notification = $claim['notification'] ?? null;
        if ($notification === null) {
            return null;
        }

        try {
            // Queue/network I/O deliberately runs after the claim transaction
            // commits. A fast worker can now observe the durable lease and can
            // never race an uncommitted outbox row.
            $this->queue->enqueue($notification);

            return 'queued';
        } catch (Throwable $exception) {
            $this->releaseQueueClaimAfterFailure($id);
            Log::warning('Dispatch push outbox enqueue failed.', [
                'outbox_id' => (string) $notification->id,
                'dispatch_request_id' => (string) $notification->dispatch_request_id,
                'exception_class' => $exception::class,
            ]);

            return 'failed';
        }
    }

    private function releaseQueueClaimAfterFailure(string $id): void
    {
        $this->withLockedHierarchy($id, function (DispatchPushOutbox $notification): void {
            if ($notification->delivered_at !== null || $notification->cancelled_at !== null) {
                return;
            }

            $attempts = ((int) $notification->attempts) + 1;
            $notification->forceFill([
                'attempts' => $attempts,
                'queued_at' => null,
                'last_attempted_at' => now(),
                'last_error_code' => 'queue_unavailable',
                'available_at' => now()->addSeconds(min(60, 5 * (2 ** min(3, $attempts - 1)))),
            ])->save();
        });
    }

    private function isDeliverablePhase(
        DispatchPushOutbox $notification,
        DispatchRequest $dispatch,
        Incident $incident,
    ): bool {
        if ((string) $notification->message_type === 'incident_preannouncement') {
            return $dispatch->status === 'draft'
                && $incident->status === 'active'
                && $this->hasPendingRecipientForToken($notification, $dispatch);
        }

        if ((string) $notification->message_type === 'dispatch_request') {
            return in_array($dispatch->status, ['sent', 'escalated'], true)
                && ! in_array($incident->status, ['resolved', 'cancelled'], true)
                && $this->hasPendingRecipientForToken($notification, $dispatch);
        }

        return $dispatch->status !== 'cancelled'
            && ! in_array($incident->status, ['resolved', 'cancelled'], true);
    }

    private function hasPendingRecipientForToken(
        DispatchPushOutbox $notification,
        DispatchRequest $dispatch,
    ): bool {
        return $dispatch->recipients()
            ->where('response_status', 'pending')
            ->whereHas('user.fcmTokens', fn ($tokens) => $tokens
                ->whereKey($notification->fcm_token_id)
                ->where('is_active', true))
            ->exists();
    }

    public function markDelivered(string $id, string $fcmTokenId): void
    {
        $this->withLockedHierarchy($id, function (DispatchPushOutbox $notification, DispatchRequest $dispatch) use ($fcmTokenId): void {
            if ((string) $notification->fcm_token_id !== $fcmTokenId
                || $notification->delivered_at !== null
                || $notification->cancelled_at !== null) {
                return;
            }

            $notification->forceFill([
                'delivered_at' => now(),
                'last_attempted_at' => now(),
                'last_error_code' => null,
            ])->save();
            $this->refreshDispatchDeliveryStatus($dispatch);
        });
    }

    public function markTerminal(string $id, string $fcmTokenId, string $errorCode): void
    {
        $this->withLockedHierarchy($id, function (DispatchPushOutbox $notification, DispatchRequest $dispatch) use ($fcmTokenId, $errorCode): void {
            if ((string) $notification->fcm_token_id !== $fcmTokenId
                || $notification->delivered_at !== null
                || $notification->cancelled_at !== null) {
                return;
            }

            $notification->forceFill([
                'cancelled_at' => now(),
                'last_attempted_at' => now(),
                'last_error_code' => $errorCode,
            ])->save();
            $this->refreshDispatchDeliveryStatus($dispatch);
        });
    }

    public function releaseAfterDeliveryFailure(string $id, string $fcmTokenId): void
    {
        $this->withLockedHierarchy($id, function (DispatchPushOutbox $notification) use ($fcmTokenId): void {
            if ((string) $notification->fcm_token_id !== $fcmTokenId
                || $notification->delivered_at !== null
                || $notification->cancelled_at !== null) {
                return;
            }

            $attempts = ((int) $notification->attempts) + 1;
            $notification->forceFill([
                'queued_at' => null,
                'attempts' => $attempts,
                'available_at' => now()->addSeconds(min(3600, 60 * (2 ** min(5, $attempts - 1)))),
                'last_attempted_at' => now(),
                'last_error_code' => 'delivery_retry_exhausted',
            ])->save();
        });
    }

    /**
     * @template TResult
     *
     * @param  Closure(DispatchPushOutbox, DispatchRequest, Incident): TResult  $callback
     * @return TResult|null
     */
    private function withLockedHierarchy(string $id, Closure $callback): mixed
    {
        // Metadata reads are deliberately unlocked. All write locks inside the
        // transaction follow incident -> dispatch -> outbox. If a relationship
        // changed in between, no mutation is made and a later pass can retry.
        $notificationMetadata = DispatchPushOutbox::query()
            ->select(['id', 'dispatch_request_id'])
            ->find($id);
        if ($notificationMetadata === null) {
            return null;
        }
        $dispatchMetadata = DispatchRequest::query()
            ->select(['id', 'incident_id'])
            ->find($notificationMetadata->dispatch_request_id);
        if ($dispatchMetadata === null) {
            return null;
        }
        $dispatchRequestId = (string) $dispatchMetadata->id;
        $incidentId = (string) $dispatchMetadata->incident_id;

        return DB::transaction(function () use ($id, $dispatchRequestId, $incidentId, $callback): mixed {
            $incident = Incident::query()->whereKey($incidentId)->lockForUpdate()->first();
            if ($incident === null) {
                return null;
            }
            $dispatch = DispatchRequest::query()->whereKey($dispatchRequestId)->lockForUpdate()->first();
            if ($dispatch === null || (string) $dispatch->incident_id !== $incidentId) {
                return null;
            }
            $notification = DispatchPushOutbox::query()->whereKey($id)->lockForUpdate()->first();
            if ($notification === null || (string) $notification->dispatch_request_id !== $dispatchRequestId) {
                return null;
            }

            return $callback($notification, $dispatch, $incident);
        });
    }

    private function refreshDispatchDeliveryStatus(DispatchRequest $dispatch): void
    {
        $rows = DispatchPushOutbox::query()
            ->where('dispatch_request_id', $dispatch->id)
            ->where('message_type', 'dispatch_request')
            ->get(['delivered_at', 'cancelled_at']);
        if ($rows->isEmpty()) {
            return;
        }
        $delivered = $rows->whereNotNull('delivered_at')->count();
        $cancelled = $rows->whereNotNull('cancelled_at')->count();
        $pending = $rows->count() - $delivered - $cancelled;
        $status = match (true) {
            $delivered > 0 && $pending === 0 && $cancelled === 0 => 'sent',
            $delivered > 0 => 'partial',
            $pending > 0 => 'queued_for_push',
            default => 'failed',
        };
        $dispatch->forceFill(['send_status' => $status])->save();
    }
}
