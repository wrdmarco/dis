<?php

namespace App\Services;

use App\Exceptions\KnmiPrecipitationRefreshException;
use App\Exceptions\WeatherDatasetOperationStartException;
use App\Models\User;
use App\Models\WeatherDatasetOperation;
use Illuminate\Http\Request;
use Throwable;

final class KnmiPrecipitationRefreshService
{
    public function __construct(
        private readonly KnmiPrecipitationConfiguration $configuration,
        private readonly WeatherDatasetOperationService $operations,
    ) {}

    public function request(User $actor, ?Request $request = null): WeatherDatasetOperation
    {
        if ($this->configuration->apiKey() === null) {
            throw new KnmiPrecipitationRefreshException('De KNMI Open Data API-sleutel is niet geconfigureerd.');
        }

        try {
            return $this->operations->request(
                WeatherDatasetOperationService::RADAR,
                $actor,
                $request,
            );
        } catch (WeatherDatasetOperationStartException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new KnmiPrecipitationRefreshException(
                'De KNMI-neerslagupdate kon niet worden gestart.',
                previous: $exception,
            );
        }
    }
}
