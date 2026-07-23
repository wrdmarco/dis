<?php

namespace App\Http\Controllers;

use App\Exceptions\KnmiForecastOperationConflictException;
use App\Exceptions\KnmiForecastOperationStartException;
use App\Exceptions\KnmiPrecipitationRefreshException;
use App\Exceptions\WeatherDatasetOperationConflictException;
use App\Exceptions\WeatherDatasetOperationStartException;
use App\Http\Requests\Admin\IndexKnmiCatalogRequest;
use App\Http\Requests\Admin\UpdateKnmiSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\AdminKnmiDatasetService;
use App\Services\KnmiCatalogService;
use App\Services\KnmiForecastOperationService;
use App\Services\KnmiPrecipitationRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminKnmiController extends Controller
{
    public function __construct(
        private readonly KnmiForecastOperationService $operations,
        private readonly KnmiPrecipitationRefreshService $precipitationRefresh,
        private readonly AdminKnmiDatasetService $datasets,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success([
            ...$this->operations->status(),
            'datasets' => $this->datasets->datasets(),
        ]);
    }

    public function catalog(IndexKnmiCatalogRequest $request, KnmiCatalogService $catalog): JsonResponse
    {
        /** @var array{query?: string|null, page?: int, per_page?: int, status?: string|null, license?: string|null} $validated */
        $validated = $request->validated();

        return ApiResponse::success($catalog->search(
            query: $validated['query'] ?? null,
            page: $validated['page'] ?? 1,
            perPage: $validated['per_page'] ?? 20,
            status: $validated['status'] ?? null,
            license: $validated['license'] ?? null,
        ));
    }

    public function update(UpdateKnmiSettingsRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);

        /** @var array{open_data_api_key?: string, edr_api_key?: string} $keys */
        $keys = $request->validated();

        return ApiResponse::success([
            ...$this->operations->updateKeys($keys, $actor, $request),
            'datasets' => $this->datasets->datasets(),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        try {
            $operation = $this->operations->requestRefresh($actor, $request);
        } catch (KnmiForecastOperationStartException $exception) {
            return ApiResponse::error('knmi_dataset_queue_unavailable', $exception->getMessage(), 503);
        } catch (KnmiForecastOperationConflictException $exception) {
            return ApiResponse::error('knmi_refresh_conflict', $exception->getMessage(), 409);
        }

        return ApiResponse::success([
            'operation' => $this->operations->operationSummary($operation),
        ], 202);
    }

    public function refreshPrecipitation(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        try {
            $operation = $this->precipitationRefresh->request($actor, $request);
        } catch (WeatherDatasetOperationStartException $exception) {
            return ApiResponse::error('knmi_dataset_queue_unavailable', $exception->getMessage(), 503);
        } catch (KnmiPrecipitationRefreshException $exception) {
            return ApiResponse::error('knmi_precipitation_refresh_conflict', $exception->getMessage(), 409);
        }

        return ApiResponse::success([
            'requested' => true,
            'operation' => $this->datasets->operationSummary($operation),
        ], 202);
    }

    public function refreshDataset(Request $request, string $dataset): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        try {
            $result = $this->datasets->requestRefresh($dataset, $actor, $request);
        } catch (KnmiForecastOperationStartException|WeatherDatasetOperationStartException $exception) {
            return ApiResponse::error('knmi_dataset_queue_unavailable', $exception->getMessage(), 503);
        } catch (KnmiForecastOperationConflictException|WeatherDatasetOperationConflictException $exception) {
            return ApiResponse::error('knmi_dataset_refresh_conflict', $exception->getMessage(), 409);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error('knmi_dataset_not_refreshable', $exception->getMessage(), 422);
        }

        return ApiResponse::success($result, 202);
    }
}
