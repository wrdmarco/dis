<?php

namespace App\Repositories;

use App\Models\DispatchPushOutbox;
use App\Models\IncidentSpeechPreparation;
use App\Models\PushQueueWorkItem;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechCacheJob;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechModelInstallation;
use App\Models\SpeechPreparedPhrase;
use App\Models\SpeechPreview;
use App\Support\ApiDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class QueueMonitorRepository
{
    private const STATES = [
        'pending',
        'queued',
        'processing',
        'retrying',
        'failed',
        'completed',
        'cancelled',
    ];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function items(string $queue, string $state, int $candidateLimit): Collection
    {
        $items = collect();
        if (in_array($queue, ['all', 'push'], true)) {
            $items = $items->concat($this->pushItems($state, $candidateLimit));
        }
        if (in_array($queue, ['all', 'speech'], true)) {
            $items = $items
                ->concat($this->speechItems(
                    IncidentSpeechPreparation::class,
                    'incident_speech_preparation',
                    'Incidentalarmspraak voorbereiden',
                    $state,
                    $candidateLimit,
                    ['progress_percent'],
                ))
                ->concat($this->speechItems(
                    SpeechManifestBuild::class,
                    'speech_manifest',
                    'Alarmeringsaudio samenstellen',
                    $state,
                    $candidateLimit,
                    ['phase', 'progress_percent', 'finished_at', 'failed_at'],
                ))
                ->concat($this->speechItems(
                    SpeechPreview::class,
                    'speech_preview',
                    'Voorbeeldmelding genereren',
                    $state,
                    $candidateLimit,
                    ['phase', 'progress_percent', 'ready_at', 'failed_at'],
                ))
                ->concat($this->speechItems(
                    SpeechPreparedPhrase::class,
                    'speech_prepared_phrase',
                    'Vaste spraakvoorbereiding genereren',
                    $state,
                    $candidateLimit,
                    ['kind', 'progress_percent', 'prepared_at'],
                ))
                ->concat($this->speechItems(
                    SpeechCacheEntry::class,
                    'speech_audio_fragment',
                    'Audiofragment genereren',
                    $state,
                    $candidateLimit,
                    ['category', 'synthesis_duration_ms'],
                ))
                ->concat($this->speechItems(
                    SpeechCacheJob::class,
                    'speech_cache_maintenance',
                    'Spraakcache verwerken',
                    $state,
                    $candidateLimit,
                    ['scope', 'progress_percent', 'finished_at'],
                ))
                ->concat($this->speechItems(
                    SpeechModelInstallation::class,
                    'speech_model_installation',
                    'Spraakmodel installeren',
                    $state,
                    $candidateLimit,
                    ['progress_percent', 'installed_at', 'failed_at'],
                ));
        }

        return $items;
    }

    /** @return array<string, int> */
    public function stateCounts(string $queue): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        if (in_array($queue, ['all', 'push'], true)) {
            $this->mergeCounts($counts, $this->pushStateCounts());
        }
        if (in_array($queue, ['all', 'speech'], true)) {
            foreach ([
                IncidentSpeechPreparation::class,
                SpeechManifestBuild::class,
                SpeechPreview::class,
                SpeechPreparedPhrase::class,
                SpeechCacheEntry::class,
                SpeechCacheJob::class,
                SpeechModelInstallation::class,
            ] as $modelClass) {
                $this->mergeCounts($counts, $this->speechStateCounts($modelClass));
            }
        }

        return $counts;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pushItems(string $state, int $limit): Collection
    {
        $query = $this->monitoredPushOutboxQuery()
            ->select([
                'dispatch_push_outbox.id',
                'dispatch_push_outbox.message_type',
                'dispatch_push_outbox.available_at',
                'dispatch_push_outbox.queued_at',
                'dispatch_push_outbox.processing_started_at',
                'dispatch_push_outbox.retry_at',
                'dispatch_push_outbox.delivered_at',
                'dispatch_push_outbox.cancelled_at',
                'dispatch_push_outbox.attempts',
                'dispatch_push_outbox.last_error_code',
                'dispatch_push_outbox.created_at',
                'dispatch_push_outbox.updated_at',
                'push_queue_lifecycle.lifecycle_id',
                'push_queue_lifecycle.lifecycle_status',
                'push_queue_lifecycle.lifecycle_attempts',
                'push_queue_lifecycle.lifecycle_error_code',
                'push_queue_lifecycle.lifecycle_queued_at',
                'push_queue_lifecycle.lifecycle_processing_started_at',
                'push_queue_lifecycle.lifecycle_next_attempt_at',
                'push_queue_lifecycle.lifecycle_finished_at',
            ])
            ->selectRaw($this->pushOutboxStateExpression().' AS monitor_state')
            ->withCasts([
                'lifecycle_attempts' => 'integer',
                'lifecycle_queued_at' => 'immutable_datetime',
                'lifecycle_processing_started_at' => 'immutable_datetime',
                'lifecycle_next_attempt_at' => 'immutable_datetime',
                'lifecycle_finished_at' => 'immutable_datetime',
            ]);
        $this->applyPushOutboxStateFilter($query, $state);

        $outboxItems = $query->latest('dispatch_push_outbox.created_at')->limit($limit)->get()
            ->map(function (DispatchPushOutbox $item): array {
                $hasCurrentLifecycle = $item->getAttribute('lifecycle_id') !== null;
                $state = (string) $item->getAttribute('monitor_state');
                $queuedAt = $hasCurrentLifecycle
                    ? ($item->getAttribute('lifecycle_queued_at') ?? $item->queued_at ?? $item->created_at)
                    : ($item->queued_at ?? $item->created_at);
                $startedAt = $hasCurrentLifecycle
                    ? $item->getAttribute('lifecycle_processing_started_at')
                    : $item->processing_started_at;
                $nextAttemptAt = $hasCurrentLifecycle
                    ? $item->getAttribute('lifecycle_next_attempt_at')
                    : ($item->retry_at ?? ($state === 'retrying' ? $item->available_at : null));
                $finishedAt = $hasCurrentLifecycle
                    ? $item->getAttribute('lifecycle_finished_at')
                    : ($item->delivered_at ?? $item->cancelled_at);
                $attempts = $hasCurrentLifecycle
                    ? (int) $item->getAttribute('lifecycle_attempts')
                    : (int) $item->attempts;
                $errorCode = $hasCurrentLifecycle
                    ? $item->getAttribute('lifecycle_error_code')
                    : $item->last_error_code;

                return $this->item(
                    id: (string) $item->id,
                    queue: 'push',
                    workloadType: 'push_notification',
                    label: $this->pushLabel((string) $item->message_type),
                    state: $state,
                    progress: $state === 'completed' ? 100 : null,
                    queuedAt: $queuedAt,
                    startedAt: $startedAt,
                    nextAttemptAt: $nextAttemptAt,
                    finishedAt: $finishedAt,
                    attempts: $attempts,
                    errorCode: $this->safeErrorCode($errorCode),
                    durationMs: null,
                    sortAt: $item->created_at,
                );
            });

        $workItems = $this->recentPushWorkItemQuery();
        $this->applyPushWorkItemStateFilter($workItems, $state);

        return $outboxItems->concat(
            $workItems
                ->select([
                    'id',
                    'safe_message_type',
                    'status',
                    'attempts',
                    'error_code',
                    'queued_at',
                    'processing_started_at',
                    'next_attempt_at',
                    'finished_at',
                    'created_at',
                ])
                ->latest('created_at')
                ->limit($limit)
                ->get()
                ->map(fn (PushQueueWorkItem $item): array => $this->item(
                    id: (string) $item->id,
                    queue: 'push',
                    workloadType: 'push_notification',
                    label: $this->pushLabel((string) $item->safe_message_type),
                    state: (string) $item->status,
                    progress: $item->status === PushQueueWorkItem::STATUS_COMPLETED ? 100 : null,
                    queuedAt: $item->queued_at,
                    startedAt: $item->processing_started_at,
                    nextAttemptAt: $item->next_attempt_at,
                    finishedAt: $item->finished_at,
                    attempts: (int) $item->attempts,
                    errorCode: $this->safeErrorCode($item->error_code),
                    durationMs: null,
                    sortAt: $item->created_at,
                )),
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $extraColumns
     * @return Collection<int, array<string, mixed>>
     */
    private function speechItems(
        string $modelClass,
        string $workloadType,
        string $label,
        string $state,
        int $limit,
        array $extraColumns,
    ): Collection {
        $query = $this->recentSpeechQuery($modelClass)
            ->select(array_values(array_unique([
                'id',
                'status',
                'error_code',
                'created_at',
                'updated_at',
                ...$extraColumns,
            ])));
        $this->applySpeechStateFilter($query, $modelClass, $state);

        return $query->latest('created_at')->limit($limit)->get()
            ->map(function (Model $item) use ($workloadType, $label): array {
                $state = $this->speechState($item::class, (string) $item->getAttribute('status'));
                $finishedAt = $item->getAttribute('finished_at')
                    ?? $item->getAttribute('ready_at')
                    ?? $item->getAttribute('prepared_at')
                    ?? $item->getAttribute('installed_at')
                    ?? $item->getAttribute('failed_at');
                if ($item instanceof IncidentSpeechPreparation
                    && in_array($state, ['completed', 'failed', 'cancelled'], true)) {
                    $finishedAt = $item->getAttribute('updated_at');
                }

                return $this->item(
                    id: (string) $item->getKey(),
                    queue: 'speech',
                    workloadType: $workloadType,
                    label: $label,
                    state: $state,
                    progress: $this->progress($item, $state),
                    queuedAt: $item->getAttribute('created_at'),
                    // These domain tables currently do not persist a true
                    // processing start instant. Never present updated_at as one:
                    // progress writes would make the displayed duration false.
                    startedAt: null,
                    nextAttemptAt: null,
                    finishedAt: $finishedAt,
                    attempts: null,
                    errorCode: $this->safeErrorCode($item->getAttribute('error_code')),
                    durationMs: $this->durationMs($item),
                    sortAt: $item->getAttribute('created_at'),
                );
            });
    }

    private function recentPushQuery(): Builder
    {
        $cutoff = now()->subHours(max(1, (int) config('dis.queue_monitor.recent_hours', 24)));

        return DispatchPushOutbox::query()->where(function (Builder $query) use ($cutoff): void {
            $query->where(fn (Builder $active) => $active
                ->whereNull('dispatch_push_outbox.delivered_at')
                ->whereNull('dispatch_push_outbox.cancelled_at'))
                ->orWhere('dispatch_push_outbox.updated_at', '>=', $cutoff);
        });
    }

    private function monitoredPushOutboxQuery(): Builder
    {
        $currentLifecycle = PushQueueWorkItem::query()
            ->select([
                'push_queue_work_items.id AS lifecycle_id',
                'push_queue_work_items.status AS lifecycle_status',
                'push_queue_work_items.attempts AS lifecycle_attempts',
                'push_queue_work_items.error_code AS lifecycle_error_code',
                'push_queue_work_items.queued_at AS lifecycle_queued_at',
                'push_queue_work_items.processing_started_at AS lifecycle_processing_started_at',
                'push_queue_work_items.next_attempt_at AS lifecycle_next_attempt_at',
                'push_queue_work_items.finished_at AS lifecycle_finished_at',
            ])
            ->whereColumn(
                'push_queue_work_items.dispatch_push_outbox_id',
                'dispatch_push_outbox.id',
            )
            ->whereNotNull('dispatch_push_outbox.queued_at')
            ->whereNull('dispatch_push_outbox.delivered_at')
            ->whereNull('dispatch_push_outbox.cancelled_at')
            ->whereColumn(
                'push_queue_work_items.created_at',
                '>=',
                'dispatch_push_outbox.queued_at',
            )
            ->latest('push_queue_work_items.created_at')
            ->latest('push_queue_work_items.id')
            ->limit(1);

        return $this->recentPushQuery()
            ->leftJoinLateral($currentLifecycle, 'push_queue_lifecycle');
    }

    private function recentPushWorkItemQuery(): Builder
    {
        $cutoff = now()->subHours(max(1, (int) config('dis.queue_monitor.recent_hours', 24)));

        return PushQueueWorkItem::query()
            ->whereNull('dispatch_push_outbox_id')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereIn('status', [
                    PushQueueWorkItem::STATUS_QUEUED,
                    PushQueueWorkItem::STATUS_PROCESSING,
                    PushQueueWorkItem::STATUS_RETRYING,
                ])->orWhere('updated_at', '>=', $cutoff);
            });
    }

    /** @param class-string<Model> $modelClass */
    private function recentSpeechQuery(string $modelClass): Builder
    {
        $cutoff = now()->subHours(max(1, (int) config('dis.queue_monitor.recent_hours', 24)));

        $activeStatuses = array_merge(
            $this->speechStatuses($modelClass, 'queued'),
            $this->speechStatuses($modelClass, 'processing'),
        );

        return $modelClass::query()->where(function (Builder $query) use (
            $cutoff,
            $activeStatuses,
            $modelClass,
        ): void {
            $query->whereIn('status', $activeStatuses);
            if ($modelClass === SpeechCacheEntry::class) {
                $query->orWhere('created_at', '>=', $cutoff);
            } else {
                $query->orWhere('updated_at', '>=', $cutoff);
            }
        });
    }

    /** @return array<string, int> */
    private function pushStateCounts(): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        $stateExpression = $this->pushOutboxStateExpression();
        $this->monitoredPushOutboxQuery()
            ->selectRaw("{$stateExpression} AS monitor_state, COUNT(*) AS aggregate")
            ->groupByRaw($stateExpression)
            ->get()
            ->each(function (DispatchPushOutbox $row) use (&$counts): void {
                $state = (string) $row->getAttribute('monitor_state');
                if (array_key_exists($state, $counts)) {
                    $counts[$state] += (int) $row->getAttribute('aggregate');
                }
            });

        $this->recentPushWorkItemQuery()
            ->select(['status'])
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('status')
            ->get()
            ->each(function (PushQueueWorkItem $row) use (&$counts): void {
                $state = (string) $row->status;
                if (array_key_exists($state, $counts)) {
                    $counts[$state] += (int) $row->getAttribute('aggregate');
                }
            });

        return $counts;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string, int>
     */
    private function speechStateCounts(string $modelClass): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        $this->recentSpeechQuery($modelClass)
            ->select(['status'])
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('status')
            ->get()
            ->each(function (Model $row) use (&$counts, $modelClass): void {
                $counts[$this->speechState($modelClass, (string) $row->getAttribute('status'))]
                    += (int) $row->getAttribute('aggregate');
            });

        return $counts;
    }

    private function applyPushOutboxStateFilter(Builder $query, string $state): void
    {
        if ($state === 'all') {
            return;
        }

        $query->whereRaw('('.$this->pushOutboxStateExpression().') = ?', [$state]);
    }

    private function applyPushWorkItemStateFilter(Builder $query, string $state): void
    {
        if ($state !== 'all') {
            $query->where('status', $state);
        }
    }

    /** @param class-string<Model> $modelClass */
    private function applySpeechStateFilter(Builder $query, string $modelClass, string $state): void
    {
        if ($state === 'all') {
            return;
        }

        if ($state === 'failed') {
            $nonFailedStatuses = array_merge(
                $this->speechStatuses($modelClass, 'completed'),
                $this->speechStatuses($modelClass, 'processing'),
                $this->speechStatuses($modelClass, 'queued'),
                $this->speechStatuses($modelClass, 'cancelled'),
            );
            $query->whereNotIn('status', $nonFailedStatuses);

            return;
        }

        $query->whereIn('status', $this->speechStatuses($modelClass, $state));
    }

    private function pushOutboxStateExpression(): string
    {
        return <<<'SQL'
CASE
    WHEN dispatch_push_outbox.delivered_at IS NOT NULL THEN 'completed'
    WHEN dispatch_push_outbox.cancelled_at IS NOT NULL
        AND dispatch_push_outbox.last_error_code = 'provider_rejected' THEN 'failed'
    WHEN dispatch_push_outbox.cancelled_at IS NOT NULL THEN 'cancelled'
    WHEN push_queue_lifecycle.lifecycle_status IS NOT NULL
        THEN push_queue_lifecycle.lifecycle_status
    WHEN dispatch_push_outbox.processing_started_at IS NOT NULL THEN 'processing'
    WHEN dispatch_push_outbox.retry_at IS NOT NULL
        OR dispatch_push_outbox.attempts > 0 THEN 'retrying'
    WHEN dispatch_push_outbox.queued_at IS NOT NULL THEN 'queued'
    ELSE 'pending'
END
SQL;
    }

    /** @param class-string<Model> $modelClass */
    private function speechState(string $modelClass, string $status): string
    {
        foreach (['completed', 'failed', 'cancelled', 'processing', 'queued'] as $state) {
            if (in_array($status, $this->speechStatuses($modelClass, $state), true)) {
                return $state;
            }
        }

        // Unknown states are fail-closed. Treating a new terminal state as
        // queued would leave an apparently endless workload in operations.
        return 'failed';
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    private function speechStatuses(string $modelClass, string $state): array
    {
        if ($modelClass === IncidentSpeechPreparation::class) {
            return match ($state) {
                'completed' => [IncidentSpeechPreparation::STATUS_READY],
                'failed' => [IncidentSpeechPreparation::STATUS_FAILED],
                'cancelled' => [
                    IncidentSpeechPreparation::STATUS_CANCELLED,
                    IncidentSpeechPreparation::STATUS_DISABLED,
                    IncidentSpeechPreparation::STATUS_NOT_SCHEDULED,
                ],
                'processing' => [IncidentSpeechPreparation::STATUS_PROCESSING],
                'queued' => [IncidentSpeechPreparation::STATUS_QUEUED],
                default => [],
            };
        }

        if ($modelClass === SpeechModelInstallation::class) {
            return match ($state) {
                'completed' => ['installed'],
                'failed' => ['failed'],
                'processing' => ['installing'],
                default => [],
            };
        }

        return match ($state) {
            'completed' => ['ready'],
            'failed' => ['failed'],
            'processing' => ['processing'],
            'queued' => ['queued'],
            default => [],
        };
    }

    private function pushLabel(string $messageType): string
    {
        return match ($messageType) {
            'dispatch_request' => 'Alarmeringspush',
            'incident_preannouncement' => 'Vooraankondigingspush',
            'dispatch_response_sync' => 'Reactiebevestiging',
            'dispatch_update' => 'Inzetupdate',
            'incident_cancelled' => 'Incidentannulering',
            'location_share_request' => 'Locatieverzoek',
            'location_sharing_stopped' => 'Locatiedeling gestopt',
            'manual_admin' => 'Handmatige pushmelding',
            'device_presence_ping' => 'Appbereikbaarheid controleren',
            'session_revoked' => 'Sessiebeëindiging',
            default => 'Pushmelding',
        };
    }

    private function progress(Model $item, string $state): ?int
    {
        $progress = $item->getAttribute('progress_percent');
        if (is_numeric($progress)) {
            return max(0, min(100, (int) $progress));
        }

        return $state === 'completed' ? 100 : null;
    }

    private function durationMs(Model $item): ?int
    {
        $duration = $item->getAttribute('synthesis_duration_ms');

        return is_numeric($duration) && (int) $duration >= 0 ? (int) $duration : null;
    }

    private function safeErrorCode(mixed $errorCode): ?string
    {
        return is_string($errorCode)
            && preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/', $errorCode) === 1
                ? $errorCode
                : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        string $id,
        string $queue,
        string $workloadType,
        string $label,
        string $state,
        ?int $progress,
        mixed $queuedAt,
        mixed $startedAt,
        mixed $nextAttemptAt,
        mixed $finishedAt,
        ?int $attempts,
        ?string $errorCode,
        ?int $durationMs,
        mixed $sortAt,
    ): array {
        return [
            'id' => $id,
            'queue' => $queue,
            'workload_type' => $workloadType,
            'label' => $label,
            'state' => $state,
            'progress_percent' => $progress,
            'queued_at' => ApiDateTime::dateTime($queuedAt),
            'started_at' => ApiDateTime::dateTime($startedAt),
            'next_attempt_at' => ApiDateTime::dateTime($nextAttemptAt),
            'finished_at' => ApiDateTime::dateTime($finishedAt),
            'attempts' => $attempts,
            'error_code' => $errorCode,
            'duration_ms' => $durationMs,
            '_sort_at' => $sortAt instanceof \DateTimeInterface ? $sortAt->getTimestamp() : 0,
        ];
    }

    /** @param array<string, int> $target @param array<string, int> $source */
    private function mergeCounts(array &$target, array $source): void
    {
        foreach ($source as $state => $count) {
            $target[$state] += $count;
        }
    }
}
