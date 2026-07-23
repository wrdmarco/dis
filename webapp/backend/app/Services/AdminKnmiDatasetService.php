<?php

namespace App\Services;

use App\Exceptions\WeatherDatasetOperationConflictException;
use App\Models\KnmiForecastOperation;
use App\Models\KnmiForecastSnapshot;
use App\Models\User;
use App\Models\WeatherDatasetOperation;
use App\Repositories\KnmiPrecipitationSnapshotRepository;
use App\Support\ApiDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

final class AdminKnmiDatasetService
{
    public const HARMONIE = 'harmonie_arome_cy43_p1';

    public const KNMI_EDR = 'knmi_edr_observations';

    public const OPEN_METEO = 'open_meteo';

    public const NOAA_SWPC_KP = 'noaa_swpc_kp';

    /** @var list<string> */
    public const RUNTIME_KEYS = [
        self::HARMONIE,
        WeatherDatasetOperationService::RADAR,
        WeatherDatasetOperationService::PRECIPITATION_PROBABILITY,
        self::KNMI_EDR,
        WeatherDatasetOperationService::EUMETSAT_LIGHTNING,
        self::OPEN_METEO,
        self::NOAA_SWPC_KP,
    ];

    public function __construct(
        private readonly KnmiForecastOperationService $forecastOperations,
        private readonly KnmiOpenDataConfiguration $openData,
        private readonly KnmiEdrConfiguration $edr,
        private readonly KnmiPrecipitationConfiguration $precipitationConfiguration,
        private readonly KnmiPrecipitationSnapshotRepository $precipitationSnapshots,
        private readonly KnmiPrecipitationRadarService $radar,
        private readonly EumetsatLightningRadarService $lightning,
        private readonly WeatherDatasetOperationService $datasetOperations,
    ) {}

    /** @return list<array<string, mixed>> */
    public function datasets(): array
    {
        return [
            $this->harmonie(),
            $this->radar(),
            $this->probability(),
            $this->edr(),
            $this->lightning(),
            $this->openMeteo(),
            $this->noaa(),
        ];
    }

    /**
     * @return array{dataset_key: string, operation: array<string, mixed>}
     *
     * @throws WeatherDatasetOperationConflictException
     */
    public function requestRefresh(string $datasetKey, User $actor, Request $request): array
    {
        if (! in_array($datasetKey, self::RUNTIME_KEYS, true)) {
            throw new \InvalidArgumentException('Onbekende databron.');
        }
        if ($datasetKey === self::HARMONIE) {
            $operation = $this->forecastOperations->requestRefresh($actor, $request);

            return [
                'dataset_key' => $datasetKey,
                'operation' => $this->forecastOperation($operation),
            ];
        }
        if (in_array($datasetKey, [
            WeatherDatasetOperationService::RADAR,
            WeatherDatasetOperationService::PRECIPITATION_PROBABILITY,
        ], true) && ! $this->openData->isConfigured()) {
            throw new \InvalidArgumentException('Een aparte KNMI Open Data API-sleutel is vereist.');
        }
        if (! in_array($datasetKey, [
            WeatherDatasetOperationService::RADAR,
            WeatherDatasetOperationService::PRECIPITATION_PROBABILITY,
            WeatherDatasetOperationService::EUMETSAT_LIGHTNING,
        ], true)) {
            throw new \InvalidArgumentException('Deze bron wordt veilig op aanvraag gelezen en heeft geen lokale updateactie.');
        }
        $operation = $this->datasetOperations->request($datasetKey, $actor, $request);

        return [
            'dataset_key' => $datasetKey,
            'operation' => $this->datasetOperations->operationSummary($operation),
        ];
    }

    /** @return array<string, mixed> */
    public function operationSummary(WeatherDatasetOperation $operation): array
    {
        return $this->datasetOperations->operationSummary($operation);
    }

    /** @return array<string, mixed> */
    private function harmonie(): array
    {
        $snapshot = KnmiForecastSnapshot::query()
            ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
            ->first();
        $operation = KnmiForecastOperation::query()->latest('created_at')->latest('id')->first();
        $status = 'unavailable';
        $note = 'Er is nog geen gevalideerde lokale HARMONIE-modelset beschikbaar.';
        if ($snapshot !== null) {
            $modelRun = CarbonImmutable::instance($snapshot->model_run_at)->utc();
            $forecastEnd = CarbonImmutable::instance($snapshot->forecast_end_at)->utc();
            $stale = $modelRun->lessThan(now('UTC')->subSeconds(
                max(3600, min(86400, (int) config('dis.knmi_forecast.maximum_model_age_seconds', 21600))),
            )) || $forecastEnd->lessThan(now('UTC'));
            $status = $stale ? 'stale' : 'current';
            $note = $stale ? 'De lokale HARMONIE-modelset is ouder dan de operationele versheidsgrens.' : null;
        } elseif (! $this->openData->isConfigured()) {
            $status = 'not_configured';
            $note = 'Een aparte KNMI Open Data API-sleutel is vereist.';
        }

        return $this->runtimeItem(
            key: self::HARMONIE,
            provider: 'KNMI',
            dataset: 'harmonie_arome_cy43_p1',
            version: '1.0',
            consumers: ['/weather bewolking', '/uav-forecast bewolking'],
            storageMode: 'local_snapshot',
            status: $status,
            configured: $this->openData->isConfigured(),
            referenceAt: ApiDateTime::dateTime($snapshot?->model_run_at),
            refreshedAt: ApiDateTime::dateTime($snapshot?->activated_at),
            nextUpdateAt: $this->nextThreeHourlyAtMinute(17),
            sourceUrl: 'https://dataplatform.knmi.nl/dataset/harmonie-arome-cy43-p1-1-0',
            availabilityNote: $note,
            latestError: $this->forecastError($operation),
            refreshable: true,
            operation: $operation === null ? null : $this->forecastOperation($operation),
        );
    }

    /** @return array<string, mixed> */
    private function radar(): array
    {
        try {
            $metadata = $this->radar->metadata();
        } catch (Throwable) {
            $metadata = ['available' => false, 'stale' => false];
        }
        $operation = $this->datasetOperations->latestForDataset(WeatherDatasetOperationService::RADAR);
        $available = ($metadata['available'] ?? false) === true;
        $stale = ($metadata['stale'] ?? false) === true;
        $status = $available ? ($stale ? 'stale' : 'current') : 'unavailable';
        if (! $available && ! $this->openData->isConfigured()) {
            $status = 'not_configured';
        }

        return $this->runtimeItem(
            key: WeatherDatasetOperationService::RADAR,
            provider: 'KNMI',
            dataset: 'radar_forecast',
            version: '2.0',
            consumers: ['/weather buienradar', '/uav-forecast neerslag 0–2 uur', 'wallboard buienradar'],
            storageMode: 'local_snapshot',
            status: $status,
            configured: $this->openData->isConfigured(),
            referenceAt: $this->string($metadata['reference_time'] ?? null),
            refreshedAt: $this->string($metadata['refreshed_at'] ?? null),
            nextUpdateAt: $this->nextFiveMinuteAtOffset(4),
            sourceUrl: 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
            availabilityNote: $this->string($metadata['availability_note'] ?? null)
                ?? (! $available && ! $this->openData->isConfigured()
                    ? 'Een aparte KNMI Open Data API-sleutel is vereist.'
                    : null),
            latestError: $this->datasetError(WeatherDatasetOperationService::RADAR, $operation),
            refreshable: true,
            operation: $operation === null ? null : $this->datasetOperations->operationSummary($operation),
        );
    }

    /** @return array<string, mixed> */
    private function probability(): array
    {
        $snapshot = null;
        try {
            $snapshot = $this->precipitationSnapshots->activeSnapshot();
        } catch (Throwable) {
            // The inventory remains available when a local manifest is damaged.
        }
        $file = is_array($snapshot) && is_array($snapshot['files']['probability'] ?? null)
            ? $snapshot['files']['probability']
            : null;
        $referenceAt = is_array($file)
            ? $this->string($file['reference_time'] ?? null)
                ?? $this->string($snapshot['reference_time'] ?? null)
            : null;
        $refreshedAt = is_array($file)
            ? $this->string($file['activated_at'] ?? null)
                ?? $this->string($snapshot['activated_at'] ?? null)
            : null;
        $stale = $referenceAt !== null && $this->olderThan(
            $referenceAt,
            $this->precipitationConfiguration->maximumReferenceAgeSeconds(),
        );
        $status = $file === null ? 'unavailable' : ($stale ? 'stale' : 'current');
        if ($file === null && ! $this->openData->isConfigured()) {
            $status = 'not_configured';
        }
        $operation = $this->datasetOperations->latestForDataset(
            WeatherDatasetOperationService::PRECIPITATION_PROBABILITY,
        );

        return $this->runtimeItem(
            key: WeatherDatasetOperationService::PRECIPITATION_PROBABILITY,
            provider: 'KNMI',
            dataset: 'seamless_precipitation_ensemble_forecast_probabilities',
            version: '1.0',
            consumers: ['/weather neerslagkans uur 3', '/uav-forecast buien +3 uur'],
            storageMode: 'local_snapshot',
            status: $status,
            configured: $this->openData->isConfigured(),
            referenceAt: $referenceAt,
            refreshedAt: $refreshedAt,
            nextUpdateAt: $this->nextFiveMinuteAtOffset(4),
            sourceUrl: 'https://dataplatform.knmi.nl/dataset/seamless-precipitation-ensemble-forecast-probabilities-1-0',
            availabilityNote: match ($status) {
                'not_configured' => 'Een aparte KNMI Open Data API-sleutel is vereist.',
                'unavailable' => 'Er is geen gevalideerde lokale kansverwachting beschikbaar; de buienradar blijft zelfstandig bruikbaar.',
                'stale' => 'De lokale kansverwachting is ouder dan de operationele versheidsgrens.',
                default => null,
            },
            latestError: $this->datasetError(
                WeatherDatasetOperationService::PRECIPITATION_PROBABILITY,
                $operation,
            ),
            refreshable: true,
            operation: $operation === null ? null : $this->datasetOperations->operationSummary($operation),
        );
    }

    /** @return array<string, mixed> */
    private function edr(): array
    {
        return $this->runtimeItem(
            key: self::KNMI_EDR,
            provider: 'KNMI',
            dataset: '10-minute-in-situ-meteorological-observations',
            version: '1.0',
            consumers: ['/uav-forecast gemeten wolkenbasis'],
            storageMode: 'local_cache',
            status: $this->edr->isConfigured() ? 'on_demand' : 'not_configured',
            configured: $this->edr->isConfigured(),
            referenceAt: null,
            refreshedAt: null,
            nextUpdateAt: null,
            sourceUrl: 'https://dataplatform.knmi.nl/dataset/10-minute-in-situ-meteorological-observations-1-0',
            availabilityNote: $this->edr->isConfigured()
                ? 'Deze EDR-bron wordt op aanvraag gelezen en kort lokaal gecachet; er is geen aparte scheduler.'
                : 'Een aparte KNMI EDR API-sleutel is vereist.',
            latestError: null,
            refreshable: false,
            operation: null,
            category: 'on_demand',
        );
    }

    /** @return array<string, mixed> */
    private function lightning(): array
    {
        try {
            $metadata = $this->lightning->metadata();
        } catch (Throwable) {
            $metadata = ['available' => false, 'stale' => false];
        }
        $operation = $this->datasetOperations->latestForDataset(
            WeatherDatasetOperationService::EUMETSAT_LIGHTNING,
        );
        $available = ($metadata['available'] ?? false) === true;
        $stale = ($metadata['stale'] ?? false) === true;

        return $this->runtimeItem(
            key: WeatherDatasetOperationService::EUMETSAT_LIGHTNING,
            provider: 'EUMETSAT',
            dataset: 'MTG Lightning Imager Accumulated Flash Area',
            version: null,
            consumers: ['/weather bliksemradar', 'wallboard bliksemradar'],
            storageMode: 'local_snapshot',
            status: $stale ? 'stale' : ($available ? 'current' : 'unavailable'),
            configured: true,
            referenceAt: $this->string($metadata['latest_frame_at'] ?? null),
            refreshedAt: $this->string($metadata['refreshed_at'] ?? null),
            nextUpdateAt: $this->nextFiveMinuteAtOffset(0),
            sourceUrl: 'https://view.eumetsat.int/',
            availabilityNote: $this->string($metadata['availability_note'] ?? null),
            latestError: $this->datasetError(WeatherDatasetOperationService::EUMETSAT_LIGHTNING, $operation),
            refreshable: true,
            operation: $operation === null ? null : $this->datasetOperations->operationSummary($operation),
        );
    }

    /** @return array<string, mixed> */
    private function openMeteo(): array
    {
        return $this->runtimeItem(
            key: self::OPEN_METEO,
            provider: 'Open-Meteo',
            dataset: 'Forecast API',
            version: null,
            consumers: ['/uav-forecast temperatuur, wind, zicht, daglicht en onweersindicatie'],
            storageMode: 'remote_on_demand',
            status: 'on_demand',
            configured: true,
            referenceAt: null,
            refreshedAt: null,
            nextUpdateAt: null,
            sourceUrl: 'https://open-meteo.com/en/docs',
            availabilityNote: 'Deze externe bron wordt op aanvraag gelezen en kort lokaal gecachet; er is geen aparte scheduler.',
            latestError: null,
            refreshable: false,
            operation: null,
            category: 'on_demand',
        );
    }

    /** @return array<string, mixed> */
    private function noaa(): array
    {
        return $this->runtimeItem(
            key: self::NOAA_SWPC_KP,
            provider: 'NOAA SWPC',
            dataset: 'Planetary K-index',
            version: null,
            consumers: ['/uav-forecast Kp-index'],
            storageMode: 'remote_on_demand',
            status: 'on_demand',
            configured: true,
            referenceAt: null,
            refreshedAt: null,
            nextUpdateAt: null,
            sourceUrl: 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json',
            availabilityNote: 'Deze externe bron wordt op aanvraag gelezen en kort lokaal gecachet; er is geen aparte scheduler.',
            latestError: null,
            refreshable: false,
            operation: null,
            category: 'on_demand',
        );
    }

    /**
     * @param  list<string>  $consumers
     * @param  array{code: string, message: string, at: string}|null  $latestError
     * @param  array<string, mixed>|null  $operation
     * @return array<string, mixed>
     */
    private function runtimeItem(
        string $key,
        string $provider,
        ?string $dataset,
        ?string $version,
        array $consumers,
        string $storageMode,
        string $status,
        bool $configured,
        ?string $referenceAt,
        ?string $refreshedAt,
        ?string $nextUpdateAt,
        string $sourceUrl,
        ?string $availabilityNote,
        ?array $latestError,
        bool $refreshable,
        ?array $operation,
        string $category = 'active',
    ): array {
        return [
            'key' => $key,
            'provider' => $provider,
            'dataset' => $dataset,
            'version' => $version,
            'consumers' => $consumers,
            'category' => $category,
            'storage_mode' => $storageMode,
            'status' => $status,
            'configured' => $configured,
            'reference_at' => $referenceAt,
            'refreshed_at' => $refreshedAt,
            'next_update_at' => $nextUpdateAt,
            'source_url' => $sourceUrl,
            'availability_note' => $availabilityNote,
            'latest_error' => $latestError,
            'refreshable' => $refreshable,
            'operation' => $operation,
        ];
    }

    /** @return array<string, mixed> */
    private function forecastOperation(KnmiForecastOperation $operation): array
    {
        return [
            'id' => (string) $operation->id,
            'dataset_keys' => [self::HARMONIE],
            'state' => (string) $operation->state,
            'stage' => (string) $operation->stage,
            'message' => (string) $operation->message,
            'progress_percent' => $operation->progress_percent,
            'started_at' => ApiDateTime::dateTime($operation->started_at),
            'finished_at' => ApiDateTime::dateTime($operation->finished_at),
        ];
    }

    /** @return array{code: string, message: string, at: string}|null */
    private function forecastError(?KnmiForecastOperation $operation): ?array
    {
        if ($operation === null || $operation->state !== KnmiForecastOperation::STATE_FAILED) {
            return null;
        }
        $at = ApiDateTime::dateTime($operation->finished_at ?? $operation->updated_at);

        return $at === null ? null : [
            'code' => $this->string($operation->error_code) ?? 'refresh_failed',
            'message' => (string) $operation->message,
            'at' => $at,
        ];
    }

    /** @return array{code: string, message: string, at: string}|null */
    private function datasetError(string $datasetKey, ?WeatherDatasetOperation $operation): ?array
    {
        if ($operation === null) {
            return null;
        }
        $datasetResult = is_array($operation->result)
            && is_array($operation->result['datasets'] ?? null)
            && is_array($operation->result['datasets'][$datasetKey] ?? null)
                ? $operation->result['datasets'][$datasetKey]
                : null;
        $resultCode = is_array($datasetResult) ? $this->string($datasetResult['error_code'] ?? null) : null;
        $resultMessage = is_array($datasetResult) ? $this->string($datasetResult['error_message'] ?? null) : null;
        $operationApplies = $operation->dataset_key === $datasetKey
            && $operation->state === WeatherDatasetOperation::STATE_FAILED;
        if ($resultCode === null && ! $operationApplies) {
            return null;
        }
        $at = ApiDateTime::dateTime($operation->finished_at ?? $operation->updated_at);

        return $at === null ? null : [
            'code' => $resultCode ?? $this->string($operation->error_code) ?? 'refresh_failed',
            'message' => $resultMessage
                ?? $this->string($operation->error_message)
                ?? 'De laatste update is mislukt.',
            'at' => $at,
        ];
    }

    private function nextThreeHourlyAtMinute(int $minute): string
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now('UTC')->setTimezone($timezone);
        $candidate = $now->startOfHour()->minute($minute)->second(0);
        while ($candidate->lessThanOrEqualTo($now)
            || $candidate->hour % 3 !== 0) {
            $candidate = $candidate->addHour()->startOfHour()->minute($minute);
        }

        return $candidate->utc()->toIso8601String();
    }

    private function nextFiveMinuteAtOffset(int $offset): string
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $candidate = CarbonImmutable::now('UTC')
            ->setTimezone($timezone)
            ->startOfMinute()
            ->addMinute();
        while ($candidate->minute % 5 !== $offset) {
            $candidate = $candidate->addMinute();
        }

        return $candidate->utc()->toIso8601String();
    }

    private function olderThan(string $timestamp, int $seconds): bool
    {
        try {
            return CarbonImmutable::parse($timestamp)->utc()->lessThan(
                CarbonImmutable::now('UTC')->subSeconds($seconds),
            );
        } catch (Throwable) {
            return true;
        }
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
