<?php

namespace App\Services;

use App\Contracts\DispatchNotificationQueue;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\Incident;
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
                'available_at' => now(),
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
        return $this->withLockedHierarchy($id, function (
            DispatchPushOutbox $notification,
            DispatchRequest $dispatch,
            Incident $incident,
        ): ?string {
            $staleQueuedAt = now()->subMinutes(self::QUEUE_LEASE_MINUTES);
            if ($notification->delivered_at !== null
                || $notification->cancelled_at !== null
                || $notification->available_at?->isFuture() === true
                || ($notification->queued_at !== null && $notification->queued_at->greaterThan($staleQueuedAt))) {
                return null;
            }
            if (! $this->isDeliverablePhase($notification, $dispatch, $incident)) {
                $notification->forceFill([
                    'cancelled_at' => now(),
                    'last_error_code' => 'dispatch_not_deliverable',
                ])->save();

                return 'cancelled';
            }

            try {
                $this->queue->enqueue($notification);
                $notification->forceFill([
                    'queued_at' => now(),
                    'last_attempted_at' => now(),
                    'last_error_code' => null,
                ])->save();

                return 'queued';
            } catch (Throwable $exception) {
                $attempts = ((int) $notification->attempts) + 1;
                $notification->forceFill([
                    'attempts' => $attempts,
                    'queued_at' => null,
                    'last_attempted_at' => now(),
                    'last_error_code' => 'queue_unavailable',
                    'available_at' => now()->addSeconds(min(60, 5 * (2 ** min(3, $attempts - 1)))),
                ])->save();
                Log::warning('Dispatch push outbox enqueue failed.', [
                    'outbox_id' => (string) $notification->id,
                    'dispatch_request_id' => (string) $notification->dispatch_request_id,
                    'exception_class' => $exception::class,
                ]);

                return 'failed';
            }
        });
    }

    private function isDeliverablePhase(
        DispatchPushOutbox $notification,
        DispatchRequest $dispatch,
        Incident $incident,
    ): bool {
        if ((string) $notification->message_type === 'incident_preannouncement') {
            return $dispatch->status === 'draft' && $incident->status === 'active';
        }

        if ((string) $notification->message_type === 'dispatch_request') {
            return in_array($dispatch->status, ['sent', 'escalated'], true)
                && ! in_array($incident->status, ['resolved', 'cancelled'], true);
        }

        return $dispatch->status !== 'cancelled'
            && ! in_array($incident->status, ['resolved', 'cancelled'], true);
    }

    public function markDelivered(string $id, string $fcmTokenId): void
    {
        $this->withLockedHierarchy($id, function (DispatchPushOutbox $notification) use ($fcmTokenId): void {
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
        });
    }

    public function markTerminal(string $id, string $fcmTokenId, string $errorCode): void
    {
        $this->withLockedHierarchy($id, function (DispatchPushOutbox $notification) use ($fcmTokenId, $errorCode): void {
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
}
