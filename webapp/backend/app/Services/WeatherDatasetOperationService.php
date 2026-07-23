<?php

namespace App\Services;

use App\Exceptions\KnmiPrecipitationImportException;
use App\Exceptions\WeatherDatasetOperationConflictException;
use App\Exceptions\WeatherDatasetOperationStartException;
use App\Jobs\RefreshWeatherDatasetOperation;
use App\Models\User;
use App\Models\WeatherDatasetOperation;
use App\Support\ApiDateTime;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WeatherDatasetOperationService
{
    private const QUEUED_STALE_MINUTES = 45;

    private const RUNNING_STALE_MINUTES = 25;

    public const RADAR = 'radar_forecast';

    public const PRECIPITATION_PROBABILITY = 'seamless_precipitation_ensemble_forecast_probabilities';

    public const EUMETSAT_LIGHTNING = 'eumetsat_mtg_li';

    /** @var list<string> */
    private const REFRESHABLE_DATASETS = [
        self::RADAR,
        self::PRECIPITATION_PROBABILITY,
        self::EUMETSAT_LIGHTNING,
    ];

    public function __construct(
        private readonly KnmiPrecipitationImportService $precipitation,
        private readonly KnmiPrecipitationOutlookService $precipitationOutlooks,
        private readonly WallboardForecastLocationService $forecastLocations,
        private readonly EumetsatLightningImportService $lightning,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws WeatherDatasetOperationConflictException
     */
    public function request(
        string $datasetKey,
        ?User $actor = null,
        ?Request $request = null,
        bool $scheduled = false,
    ): WeatherDatasetOperation {
        if (! in_array($datasetKey, self::REFRESHABLE_DATASETS, true)) {
            throw new \InvalidArgumentException('Deze databron ondersteunt geen handmatige lokale update.');
        }
        $this->recoverStaleOperation($this->activeKey($datasetKey));

        try {
            $operation = DB::transaction(function () use ($datasetKey, $actor, $request, $scheduled): WeatherDatasetOperation {
                $operation = WeatherDatasetOperation::query()->create([
                    'dataset_key' => $datasetKey,
                    'dataset_keys' => $this->affectedDatasetKeys($datasetKey),
                    'active_key' => $this->activeKey($datasetKey),
                    'scheduled' => $scheduled,
                    'state' => WeatherDatasetOperation::STATE_QUEUED,
                    'stage' => 'queued',
                    'message' => $scheduled
                        ? 'Automatische databronupdate staat klaar.'
                        : 'Databronupdate staat klaar.',
                    'progress_percent' => 0,
                    'requested_by' => $actor?->id,
                ]);
                if (! $scheduled) {
                    $this->audit->record(
                        action: 'weather.dataset.refresh_requested',
                        target: $operation,
                        actor: $actor,
                        metadata: [
                            'dataset_key' => $datasetKey,
                            'dataset_keys' => $operation->dataset_keys,
                        ],
                        request: $request,
                    );
                }

                return $operation;
            });
        } catch (QueryException $exception) {
            if (WeatherDatasetOperation::query()->where('active_key', $this->activeKey($datasetKey))->exists()) {
                throw new WeatherDatasetOperationConflictException(
                    'Er draait al een update voor deze databron.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        try {
            RefreshWeatherDatasetOperation::dispatch($operation->id);
        } catch (Throwable $exception) {
            $operation->forceFill([
                'state' => WeatherDatasetOperation::STATE_FAILED,
                'stage' => 'failed',
                'active_key' => null,
                'message' => 'Databronupdate kon niet aan de wachtrij worden toegevoegd.',
                'progress_percent' => 0,
                'error_code' => 'queue_unavailable',
                'error_message' => 'De updatewachtrij is niet beschikbaar.',
                'finished_at' => now(),
            ])->save();
            $this->logFailure($operation, 'queue_unavailable', $exception);
            $this->auditLifecycle(
                'weather.dataset.refresh_failed',
                $operation,
                ['error_code' => 'queue_unavailable'],
            );
            throw new WeatherDatasetOperationStartException(
                'Databronupdate kon niet worden gestart.',
                previous: $exception,
            );
        }

        return $operation->refresh();
    }

    public function run(string $operationId): void
    {
        $operation = DB::transaction(function () use ($operationId): ?WeatherDatasetOperation {
            $operation = WeatherDatasetOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null
                || $operation->state !== WeatherDatasetOperation::STATE_QUEUED
                || $operation->active_key !== $this->activeKey((string) $operation->dataset_key)) {
                return null;
            }
            $operation->forceFill([
                'state' => WeatherDatasetOperation::STATE_RUNNING,
                'stage' => 'refreshing',
                'message' => 'Databronnen worden opgehaald en lokaal gevalideerd.',
                'progress_percent' => 10,
                'started_at' => now(),
            ])->save();

            return $operation;
        });
        if ($operation === null) {
            return;
        }
        $this->auditLifecycle('weather.dataset.refresh_started', $operation);

        try {
            $this->markProgress(
                $operation,
                'importing',
                'Bronbestanden worden gedownload, gevalideerd en veilig klaargezet.',
                25,
            );
            $result = match ($operation->dataset_key) {
                self::RADAR, self::PRECIPITATION_PROBABILITY => $this->refreshPrecipitation(),
                self::EUMETSAT_LIGHTNING => $this->lightningResult($this->lightning->refresh()),
                default => throw new \LogicException('Unsupported weather dataset operation.'),
            };
            $this->finishFromResult($operation, $result);
        } catch (Throwable $exception) {
            $this->finishFailed($operation, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function refreshPrecipitation(): array
    {
        $result = $this->precipitation->refresh();
        $this->precipitationOutlooks->prewarmResolution($this->forecastLocations->resolve([
            'location_mode' => WallboardForecastLocationService::MODE_NETHERLANDS,
        ]));

        return $result;
    }

    public function failFromWorker(string $operationId): void
    {
        $operation = WeatherDatasetOperation::query()->find($operationId);
        if ($operation !== null && $operation->isActive()) {
            $this->finishFailed(
                $operation,
                new \RuntimeException('The weather dataset worker stopped before completion.'),
                'worker_failed',
            );
        }
    }

    /** @return array<string, mixed> */
    public function operationSummary(WeatherDatasetOperation $operation): array
    {
        $datasetKeys = is_array($operation->dataset_keys)
            ? array_values(array_filter(
                $operation->dataset_keys,
                static fn (mixed $key): bool => is_string($key) && $key !== '',
            ))
            : [];

        return [
            'id' => (string) $operation->id,
            'dataset_keys' => $datasetKeys,
            'state' => (string) $operation->state,
            'stage' => (string) $operation->stage,
            'message' => (string) $operation->message,
            'progress_percent' => $operation->progress_percent,
            'started_at' => ApiDateTime::dateTime($operation->started_at),
            'finished_at' => ApiDateTime::dateTime($operation->finished_at),
        ];
    }

    public function latestForDataset(string $datasetKey): ?WeatherDatasetOperation
    {
        $this->recoverStaleOperation($this->activeKey($datasetKey));

        return WeatherDatasetOperation::query()
            ->whereJsonContains('dataset_keys', $datasetKey)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed>|null */
    public function latestDatasetResult(string $datasetKey): ?array
    {
        $operation = $this->latestForDataset($datasetKey);
        $result = $operation?->result;
        $dataset = is_array($result) && is_array($result['datasets'] ?? null)
            ? ($result['datasets'][$datasetKey] ?? null)
            : null;

        return is_array($dataset) ? $dataset : null;
    }

    private function activeKey(string $datasetKey): string
    {
        return match ($datasetKey) {
            self::RADAR, self::PRECIPITATION_PROBABILITY => 'knmi-precipitation',
            self::EUMETSAT_LIGHTNING => 'eumetsat-lightning',
            default => 'weather-'.hash('sha256', $datasetKey),
        };
    }

    private function markProgress(
        WeatherDatasetOperation $operation,
        string $stage,
        string $message,
        int $progress,
    ): void {
        WeatherDatasetOperation::query()
            ->whereKey($operation->id)
            ->where('state', WeatherDatasetOperation::STATE_RUNNING)
            ->update([
                'stage' => $stage,
                'message' => $message,
                'progress_percent' => max(0, min(99, $progress)),
                'updated_at' => now(),
            ]);
        $operation->forceFill([
            'stage' => $stage,
            'message' => $message,
            'progress_percent' => max(0, min(99, $progress)),
        ]);
    }

    /** @return list<string> */
    private function affectedDatasetKeys(string $datasetKey): array
    {
        return match ($datasetKey) {
            self::RADAR, self::PRECIPITATION_PROBABILITY => [
                self::RADAR,
                self::PRECIPITATION_PROBABILITY,
            ],
            default => [$datasetKey],
        };
    }

    /** @param array{changed: bool, reference_time: string, snapshot_id: string} $result */
    private function lightningResult(array $result): array
    {
        $status = ($result['changed'] ?? false) === true ? 'succeeded' : 'unchanged';

        return [
            'changed' => ($result['changed'] ?? false) === true,
            'reference_time' => $result['reference_time'],
            'snapshot_id' => $result['snapshot_id'],
            'datasets' => [
                self::EUMETSAT_LIGHTNING => [
                    'status' => $status,
                    'changed' => ($result['changed'] ?? false) === true,
                    'reference_time' => $result['reference_time'],
                    'refreshed_at' => now()->utc()->toIso8601String(),
                    'error_code' => null,
                    'error_message' => null,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $result */
    private function finishFromResult(WeatherDatasetOperation $operation, array $result): void
    {
        $datasets = $this->normalizeDatasetResults($result['datasets'] ?? null);
        $selected = $datasets[$operation->dataset_key] ?? null;
        $selectedSucceeded = is_array($selected)
            && in_array($selected['status'] ?? null, ['succeeded', 'unchanged'], true);
        $errorCode = $selectedSucceeded ? null : $this->resultString($selected['error_code'] ?? null);
        $errorMessage = $selectedSucceeded ? null : $this->resultString($selected['error_message'] ?? null);

        DB::transaction(function () use (
            $operation,
            $datasets,
            $selectedSucceeded,
            $errorCode,
            $errorMessage,
        ): void {
            $locked = WeatherDatasetOperation::query()->lockForUpdate()->find($operation->id);
            if ($locked === null || ! $locked->isActive()) {
                return;
            }
            $locked->forceFill([
                'state' => $selectedSucceeded
                    ? WeatherDatasetOperation::STATE_SUCCEEDED
                    : WeatherDatasetOperation::STATE_FAILED,
                'stage' => $selectedSucceeded ? 'completed' : 'failed',
                'active_key' => null,
                'message' => $selectedSucceeded
                    ? 'Databronupdate is veilig afgerond.'
                    : 'De gekozen databron kon niet worden bijgewerkt.',
                'progress_percent' => 100,
                'result' => ['datasets' => $datasets],
                'error_code' => $errorCode ?? ($selectedSucceeded ? null : 'dataset_unavailable'),
                'error_message' => $errorMessage ?? ($selectedSucceeded ? null : 'De gekozen bron leverde geen bruikbare dataset op.'),
                'finished_at' => now(),
            ])->save();
        });
        $this->auditLifecycle(
            $selectedSucceeded ? 'weather.dataset.refresh_succeeded' : 'weather.dataset.refresh_failed',
            $operation,
            ['dataset_statuses' => array_map(
                static fn (array $dataset): mixed => $dataset['status'] ?? null,
                $datasets,
            )],
        );
    }

    private function finishFailed(
        WeatherDatasetOperation $operation,
        Throwable $exception,
        ?string $forcedCode = null,
    ): void {
        $code = $forcedCode
            ?? ($exception instanceof KnmiPrecipitationImportException
                || $exception instanceof EumetsatLightningImportException
                ? $exception->publicCode
                : 'refresh_failed');
        $message = $this->publicFailureMessage($code);

        DB::transaction(function () use ($operation, $code, $message): void {
            $locked = WeatherDatasetOperation::query()->lockForUpdate()->find($operation->id);
            if ($locked === null || ! $locked->isActive()) {
                return;
            }
            $locked->forceFill([
                'state' => WeatherDatasetOperation::STATE_FAILED,
                'stage' => 'failed',
                'active_key' => null,
                'message' => 'Databronupdate is mislukt.',
                'progress_percent' => $locked->progress_percent,
                'error_code' => $code,
                'error_message' => $message,
                'finished_at' => now(),
            ])->save();
        });
        $this->logFailure($operation, $code, $exception);
        $this->auditLifecycle('weather.dataset.refresh_failed', $operation, ['error_code' => $code]);
    }

    private function logFailure(
        WeatherDatasetOperation $operation,
        string $code,
        Throwable $exception,
    ): void {
        try {
            Log::error('Weather dataset refresh failed.', [
                'operation_id' => (string) $operation->id,
                'dataset_key' => (string) $operation->dataset_key,
                'error_code' => $code,
                'exception_class' => $exception::class,
            ]);
        } catch (Throwable) {
            // Persisted operation state remains authoritative when logging is unavailable.
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function normalizeDatasetResults(mixed $results): array
    {
        if (! is_array($results) || array_is_list($results)) {
            return [];
        }
        $normalized = [];
        foreach ($results as $key => $result) {
            if (! is_string($key)
                || ! in_array($key, [
                    self::RADAR,
                    self::PRECIPITATION_PROBABILITY,
                    self::EUMETSAT_LIGHTNING,
                ], true)
                || ! is_array($result)) {
                continue;
            }
            $status = $result['status'] ?? null;
            if (! in_array($status, ['succeeded', 'unchanged', 'failed', 'unavailable'], true)) {
                continue;
            }
            $normalized[$key] = [
                'status' => $status,
                'changed' => ($result['changed'] ?? false) === true,
                'reference_time' => $this->resultString($result['reference_time'] ?? null),
                'refreshed_at' => $this->resultString($result['refreshed_at'] ?? null)
                    ?? now()->utc()->toIso8601String(),
                'error_code' => $this->resultString($result['error_code'] ?? null),
                'error_message' => $this->resultString($result['error_message'] ?? null),
            ];
        }

        return $normalized;
    }

    private function resultString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 1000
            ? trim($value)
            : null;
    }

    private function publicFailureMessage(string $code): string
    {
        return match ($code) {
            'not_configured' => 'De vereiste bronconfiguratie ontbreekt.',
            'metadata_unavailable' => 'De bronmetadata kon niet worden opgehaald.',
            'matching_run_unavailable' => 'Er is nog geen passende bronuitgave beschikbaar.',
            'matching_run_stale', 'radar_run_stale' => 'De nieuwste bronuitgave is te oud.',
            'download_failed', 'download_url_unavailable' => 'Het bronbestand kon niet worden gedownload.',
            'worker_failed' => 'De updateworker stopte voordat de update gereed was.',
            default => 'De bron leverde geen veilig valideerbare lokale dataset op.',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function auditLifecycle(
        string $action,
        WeatherDatasetOperation $operation,
        array $metadata = [],
    ): void {
        try {
            $current = WeatherDatasetOperation::query()->find($operation->id);
            if ($current === null) {
                return;
            }
            if ($current->scheduled && $action !== 'weather.dataset.refresh_failed') {
                return;
            }
            $this->audit->record(
                action: $action,
                target: $current,
                actor: $current->requester()->first(),
                metadata: [
                    'dataset_key' => (string) $current->dataset_key,
                    'dataset_keys' => (array) $current->dataset_keys,
                    ...$metadata,
                ],
            );
        } catch (Throwable) {
            // Dataset state remains authoritative when audit storage is temporarily unavailable.
        }
    }

    private function recoverStaleOperation(string $activeKey): ?WeatherDatasetOperation
    {
        $operation = DB::transaction(function () use ($activeKey): ?WeatherDatasetOperation {
            $operation = WeatherDatasetOperation::query()
                ->where('active_key', $activeKey)
                ->lockForUpdate()
                ->first();
            if ($operation === null) {
                return null;
            }
            $queuedStale = $operation->state === WeatherDatasetOperation::STATE_QUEUED
                && $operation->created_at?->lessThan(now()->subMinutes(self::QUEUED_STALE_MINUTES));
            $runningStale = $operation->state === WeatherDatasetOperation::STATE_RUNNING
                && $operation->started_at?->lessThan(now()->subMinutes(self::RUNNING_STALE_MINUTES));
            if (! $queuedStale && ! $runningStale) {
                return null;
            }
            $operation->forceFill([
                'state' => WeatherDatasetOperation::STATE_FAILED,
                'stage' => 'failed',
                'active_key' => null,
                'message' => 'De vorige databronupdate is niet tijdig afgerond.',
                'error_code' => 'operation_stale',
                'error_message' => 'De updateworker rondde de operatie niet binnen de maximale tijd af.',
                'finished_at' => now(),
            ])->save();

            return $operation;
        });
        if ($operation !== null) {
            $this->auditLifecycle(
                'weather.dataset.refresh_failed',
                $operation,
                ['error_code' => 'operation_stale'],
            );
        }

        return $operation;
    }
}
