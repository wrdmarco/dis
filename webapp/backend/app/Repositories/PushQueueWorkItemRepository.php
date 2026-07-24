<?php

namespace App\Repositories;

use App\Models\PushQueueWorkItem;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class PushQueueWorkItemRepository
{
    public function queued(
        string $queueJobId,
        string $safeMessageType,
        ?string $dispatchPushOutboxId,
        ?int $delaySeconds,
    ): void {
        $queuedAtCandidate = CarbonImmutable::now();
        $this->mutate($queueJobId, function (?PushQueueWorkItem $item) use (
            $queueJobId,
            $safeMessageType,
            $dispatchPushOutboxId,
            $delaySeconds,
            $queuedAtCandidate,
        ): void {
            if ($item === null) {
                PushQueueWorkItem::query()->create([
                    'queue_job_id' => $queueJobId,
                    'safe_message_type' => $safeMessageType,
                    'dispatch_push_outbox_id' => $dispatchPushOutboxId,
                    'status' => PushQueueWorkItem::STATUS_QUEUED,
                    'queued_at' => $queuedAtCandidate,
                    'next_attempt_at' => $this->nextAttemptAt($delaySeconds),
                ]);

                return;
            }

            // JobQueued is raised after the Redis push. A fast worker can have
            // reached processing or even completion before this listener gets
            // the database lock, so this event may enrich but never regress.
            $item->forceFill([
                'safe_message_type' => $item->safe_message_type === 'push_notification'
                    ? $safeMessageType
                    : $item->safe_message_type,
                'dispatch_push_outbox_id' => $item->dispatch_push_outbox_id ?? $dispatchPushOutboxId,
                'queued_at' => $this->causalQueuedAt($item, $queuedAtCandidate),
            ])->save();
        });
    }

    public function processing(string $queueJobId, int $attempts): void
    {
        $this->mutate($queueJobId, function (?PushQueueWorkItem $item) use ($queueJobId, $attempts): void {
            $item ??= PushQueueWorkItem::query()->create([
                'queue_job_id' => $queueJobId,
                'safe_message_type' => 'push_notification',
                'status' => PushQueueWorkItem::STATUS_PROCESSING,
            ]);
            if ($this->isTerminal($item) || $this->isOlderAttempt($item, $attempts)) {
                return;
            }
            $item->forceFill([
                'status' => PushQueueWorkItem::STATUS_PROCESSING,
                'attempts' => max((int) $item->attempts, $attempts),
                // JobProcessing represents the start of this concrete attempt.
                // Reusing the first attempt timestamp would make a later
                // attempt's runtime include its intervening retry delay.
                'processing_started_at' => now(),
                'next_attempt_at' => null,
                'finished_at' => null,
                'error_code' => null,
            ])->save();
        });
    }

    public function retrying(
        string $queueJobId,
        int $attempts,
        ?int $delaySeconds,
        string $errorCode = 'queue_retry_scheduled',
    ): void {
        $this->mutate($queueJobId, function (?PushQueueWorkItem $item) use (
            $queueJobId,
            $attempts,
            $delaySeconds,
            $errorCode,
        ): void {
            $item ??= PushQueueWorkItem::query()->create([
                'queue_job_id' => $queueJobId,
                'safe_message_type' => 'push_notification',
                'status' => PushQueueWorkItem::STATUS_RETRYING,
            ]);
            if ($this->isTerminal($item) || $this->isOlderAttempt($item, $attempts)) {
                return;
            }
            $recoveringStale = $this->isStaleFailure($item);

            $item->forceFill([
                'status' => PushQueueWorkItem::STATUS_RETRYING,
                'attempts' => max((int) $item->attempts, $attempts),
                'processing_started_at' => $recoveringStale ? null : $item->processing_started_at,
                'next_attempt_at' => $this->nextAttemptAt($delaySeconds),
                'finished_at' => null,
                'error_code' => $errorCode,
            ])->save();
        });
    }

    public function reconcileStaleActive(int $staleAfterSeconds): int
    {
        return PushQueueWorkItem::query()
            ->whereIn('status', [
                PushQueueWorkItem::STATUS_QUEUED,
                PushQueueWorkItem::STATUS_PROCESSING,
                PushQueueWorkItem::STATUS_RETRYING,
            ])
            ->where('updated_at', '<', now()->subSeconds(max(1, $staleAfterSeconds)))
            ->update([
                // Age alone cannot prove a Redis job has failed: it may still
                // be waiting in the ready, delayed or reserved set while all
                // workers are unavailable. Keep the durable lifecycle state
                // active and surface only a safe non-terminal warning.
                'error_code' => 'queue_lifecycle_stale',
                'updated_at' => now(),
            ]);
    }

    public function completed(string $queueJobId, int $attempts): void
    {
        $this->finish(
            $queueJobId,
            PushQueueWorkItem::STATUS_COMPLETED,
            $attempts,
            null,
        );
    }

    public function failed(string $queueJobId, int $attempts, string $errorCode): void
    {
        $this->finish(
            $queueJobId,
            PushQueueWorkItem::STATUS_FAILED,
            $attempts,
            $errorCode,
        );
    }

    private function finish(string $queueJobId, string $status, int $attempts, ?string $errorCode): void
    {
        $this->mutate($queueJobId, function (?PushQueueWorkItem $item) use (
            $queueJobId,
            $status,
            $attempts,
            $errorCode,
        ): void {
            if ($item === null) {
                PushQueueWorkItem::query()->create([
                    'queue_job_id' => $queueJobId,
                    'safe_message_type' => 'push_notification',
                    'status' => $status,
                    'attempts' => $attempts,
                    'error_code' => $errorCode,
                    'finished_at' => now(),
                ]);

                return;
            }
            if ($this->isTerminal($item) || $this->isOlderAttempt($item, $attempts)) {
                return;
            }

            $item->forceFill([
                'status' => $status,
                'attempts' => max((int) $item->attempts, $attempts),
                'next_attempt_at' => null,
                'finished_at' => now(),
                'error_code' => $errorCode,
            ])->save();
        });
    }

    private function mutate(string $queueJobId, Closure $callback): void
    {
        DB::transaction(function () use ($queueJobId, $callback): void {
            // Queue and HTTP processes can observe lifecycle events in either
            // order. A transaction-scoped advisory lock serializes each opaque
            // queue identifier without ever reading or persisting its payload.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$queueJobId]);
            $item = PushQueueWorkItem::query()
                ->where('queue_job_id', $queueJobId)
                ->lockForUpdate()
                ->first();

            $callback($item);
        }, 3);
    }

    private function isTerminal(PushQueueWorkItem $item): bool
    {
        return in_array($item->status, [
            PushQueueWorkItem::STATUS_COMPLETED,
            PushQueueWorkItem::STATUS_FAILED,
        ], true) && ! $this->isStaleFailure($item);
    }

    private function isStaleFailure(PushQueueWorkItem $item): bool
    {
        return $item->status === PushQueueWorkItem::STATUS_FAILED
            && $item->error_code === 'queue_lifecycle_stale';
    }

    private function isOlderAttempt(PushQueueWorkItem $item, int $attempts): bool
    {
        return $attempts < (int) $item->attempts;
    }

    private function causalQueuedAt(
        PushQueueWorkItem $item,
        CarbonImmutable $queuedAtCandidate,
    ): CarbonImmutable {
        foreach ([
            $item->queued_at,
            $item->created_at,
            $item->processing_started_at,
            $item->finished_at,
        ] as $timestamp) {
            if (! $timestamp instanceof DateTimeInterface) {
                continue;
            }

            $candidate = CarbonImmutable::instance($timestamp);
            if ($candidate->lessThan($queuedAtCandidate)) {
                $queuedAtCandidate = $candidate;
            }
        }

        return $queuedAtCandidate;
    }

    private function nextAttemptAt(?int $delaySeconds): ?CarbonImmutable
    {
        return $delaySeconds !== null
            ? CarbonImmutable::now()->addSeconds(max(0, min(3600, $delaySeconds)))
            : null;
    }
}
