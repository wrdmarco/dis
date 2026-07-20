<?php

namespace App\Services;

use App\Exceptions\KnmiPrecipitationRefreshException;
use App\Jobs\RefreshKnmiPrecipitationOutlookSnapshot;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

final class KnmiPrecipitationRefreshService
{
    public function __construct(
        private readonly KnmiPrecipitationConfiguration $configuration,
        private readonly AuditService $audit,
    ) {}

    public function request(User $actor, ?Request $request = null): void
    {
        if ($this->configuration->apiKey() === null) {
            throw new KnmiPrecipitationRefreshException('De KNMI Open Data API-sleutel is niet geconfigureerd.');
        }

        $this->audit->record(
            action: 'weather.knmi.precipitation_refresh_requested',
            target: RefreshKnmiPrecipitationOutlookSnapshot::class,
            actor: $actor,
            metadata: [
                'source' => 'admin',
                'datasets' => [
                    'radar_forecast/2.0',
                    'seamless_precipitation_ensemble_forecast_probabilities/1.0',
                ],
            ],
            request: $request,
        );

        try {
            RefreshKnmiPrecipitationOutlookSnapshot::dispatch();
        } catch (Throwable $exception) {
            throw new KnmiPrecipitationRefreshException(
                'De KNMI-neerslagupdate kon niet worden gestart.',
                previous: $exception,
            );
        }
    }
}
