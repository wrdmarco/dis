<?php

namespace App\Services;

use App\Exceptions\KnmiForecastOperationConflictException;
use App\Jobs\RefreshKnmiForecastDataset;
use App\Models\KnmiForecastOperation;
use App\Models\KnmiForecastSnapshot;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ApiDateTime;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class KnmiForecastOperationService
{
    public function __construct(
        private readonly KnmiOpenDataConfiguration $openData,
        private readonly KnmiEdrConfiguration $edr,
        private readonly AuditService $audit,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $this->recoverStaleOperation();
        $activeOperation = KnmiForecastOperation::query()
            ->where('active_key', KnmiForecastOperation::ACTIVE_KEY)
            ->latest('created_at')
            ->first();
        $latestOperation = KnmiForecastOperation::query()->latest('created_at')->latest('id')->first();
        $snapshot = KnmiForecastSnapshot::query()
            ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
            ->first();
        $openDataSource = $this->openData->keySource();
        $dedicatedOpenDataSource = in_array($openDataSource, ['open_data_setting', 'open_data_environment'], true)
            ? $openDataSource
            : null;

        return [
            'configuration' => [
                'configured' => $this->openData->isConfigured(),
                'open_data_api_key_configured' => $dedicatedOpenDataSource !== null,
                'open_data_api_key_source' => $dedicatedOpenDataSource,
                'edr_api_key_configured' => $this->edr->isConfigured(),
                'edr_api_key_source' => $this->edr->keySource(),
                'open_data_endpoint' => $this->openData->apiBaseUrl(),
                'edr_collection_endpoint' => KnmiEdrConfiguration::COLLECTION_ENDPOINT,
                'dataset' => $this->openData->dataset(),
                'dataset_version' => $this->openData->datasetVersion(),
                'automatic_interval_hours' => 3,
            ],
            'active_snapshot' => $snapshot === null ? null : $this->snapshotSummary($snapshot),
            'active_operation' => $activeOperation === null ? null : $this->operationSummary($activeOperation),
            'latest_operation' => $latestOperation === null ? null : $this->operationSummary($latestOperation),
        ];
    }

    /**
     * @param  array{open_data_api_key?: string, edr_api_key?: string}  $keys
     * @return array<string, mixed>
     */
    public function updateKeys(array $keys, User $actor, ?Request $request = null): array
    {
        DB::transaction(function () use ($keys, $actor, $request): void {
            $updated = [];
            if (array_key_exists('open_data_api_key', $keys)) {
                $this->putKey(KnmiOpenDataConfiguration::API_KEY_SETTING, $keys['open_data_api_key'], $actor);
                $updated[] = 'open_data_api_key';
            }
            if (array_key_exists('edr_api_key', $keys)) {
                $this->putKey(KnmiEdrConfiguration::API_KEY_SETTING, $keys['edr_api_key'], $actor);
                $updated[] = 'edr_api_key';
            }
            $this->audit->record(
                action: 'weather.knmi.settings_updated',
                target: SystemSetting::class,
                actor: $actor,
                metadata: ['keys' => $updated],
                request: $request,
            );
        });

        return $this->status();
    }

    public function requestRefresh(?User $actor = null, ?Request $request = null, bool $scheduled = false): KnmiForecastOperation
    {
        $this->recoverStaleOperation();
        if (! $this->openData->isConfigured()) {
            throw new KnmiForecastOperationConflictException('De KNMI Open Data API-sleutel is niet geconfigureerd.');
        }
        try {
            $operation = DB::transaction(function () use ($actor, $request, $scheduled): KnmiForecastOperation {
                $operation = KnmiForecastOperation::query()->create([
                    'state' => KnmiForecastOperation::STATE_QUEUED,
                    'stage' => 'queued',
                    'active_key' => KnmiForecastOperation::ACTIVE_KEY,
                    'message' => $scheduled
                        ? 'Automatische KNMI-update staat klaar.'
                        : 'KNMI-update staat klaar.',
                    'progress_percent' => 0,
                    'downloaded_bytes' => 0,
                    'requested_by' => $actor?->id,
                ]);
                $this->audit->record(
                    action: $scheduled ? 'weather.knmi.refresh_scheduled' : 'weather.knmi.refresh_requested',
                    target: $operation,
                    actor: $actor,
                    metadata: ['source' => $scheduled ? 'scheduler' : 'admin'],
                    request: $request,
                );

                return $operation;
            });
        } catch (QueryException $exception) {
            if (KnmiForecastOperation::query()->where('active_key', KnmiForecastOperation::ACTIVE_KEY)->exists()) {
                throw new KnmiForecastOperationConflictException('Er draait al een KNMI-update.', previous: $exception);
            }

            throw $exception;
        }

        try {
            RefreshKnmiForecastDataset::dispatch($operation->id);
        } catch (Throwable $exception) {
            $operation->forceFill([
                'state' => KnmiForecastOperation::STATE_FAILED,
                'stage' => 'failed',
                'active_key' => null,
                'message' => 'KNMI-update kon niet aan de wachtrij worden toegevoegd.',
                'error_code' => 'queue_unavailable',
                'finished_at' => now(),
            ])->save();
            throw new KnmiForecastOperationConflictException('KNMI-update kon niet worden gestart.', previous: $exception);
        }

        return $operation->refresh();
    }

    /** @return array<string, mixed> */
    public function operationSummary(KnmiForecastOperation $operation): array
    {
        return [
            'id' => (string) $operation->id,
            'state' => (string) $operation->state,
            'stage' => (string) $operation->stage,
            'message' => (string) $operation->message,
            'progress_percent' => $operation->progress_percent,
            'downloaded_bytes' => (int) $operation->downloaded_bytes,
            'total_bytes' => $operation->total_bytes,
            'source_filename' => $operation->source_filename,
            'unchanged' => (bool) $operation->unchanged,
            'snapshot_id' => $operation->snapshot_id,
            'error_code' => $operation->error_code,
            'requested_by' => $operation->requested_by,
            'started_at' => ApiDateTime::dateTime($operation->started_at),
            'finished_at' => ApiDateTime::dateTime($operation->finished_at),
            'created_at' => ApiDateTime::dateTime($operation->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshotSummary(KnmiForecastSnapshot $snapshot): array
    {
        return [
            'id' => (string) $snapshot->id,
            'source_filename' => (string) $snapshot->source_filename,
            'source_size_bytes' => (int) $snapshot->source_size_bytes,
            'source_sha256' => (string) $snapshot->source_sha256,
            'model_run_at' => ApiDateTime::dateTime($snapshot->model_run_at),
            'forecast_start_at' => ApiDateTime::dateTime($snapshot->forecast_start_at),
            'forecast_end_at' => ApiDateTime::dateTime($snapshot->forecast_end_at),
            'member_count' => (int) $snapshot->member_count,
            'activated_at' => ApiDateTime::dateTime($snapshot->activated_at),
        ];
    }

    private function putKey(string $key, string $value, User $actor): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => trim($value),
                'is_sensitive' => true,
                'updated_by' => $actor->id,
            ],
        );
    }

    private function recoverStaleOperation(): void
    {
        DB::transaction(function (): void {
            $operation = KnmiForecastOperation::query()
                ->where('active_key', KnmiForecastOperation::ACTIVE_KEY)
                ->lockForUpdate()
                ->first();
            if ($operation === null) {
                return;
            }
            $queuedStale = $operation->state === KnmiForecastOperation::STATE_QUEUED
                && $operation->created_at?->lessThan(now()->subHours(2));
            $runningStale = $operation->state === KnmiForecastOperation::STATE_RUNNING
                && $operation->started_at?->lessThan(now()->subHours(3));
            if (! $queuedStale && ! $runningStale) {
                return;
            }
            $previousState = (string) $operation->state;
            $operation->forceFill([
                'state' => KnmiForecastOperation::STATE_FAILED,
                'stage' => 'failed',
                'active_key' => null,
                'message' => 'De vorige KNMI-update is niet tijdig afgerond.',
                'error_code' => 'operation_stale',
                'finished_at' => now(),
            ])->save();
            try {
                $this->audit->record(
                    action: 'weather.knmi.refresh_stale',
                    target: $operation,
                    actor: null,
                    metadata: ['previous_state' => $previousState],
                );
            } catch (Throwable) {
                // Stale recovery must still release the unique operation slot.
            }
        });
    }
}
