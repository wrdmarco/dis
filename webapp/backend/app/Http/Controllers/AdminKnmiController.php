<?php

namespace App\Http\Controllers;

use App\Exceptions\KnmiForecastOperationConflictException;
use App\Exceptions\KnmiPrecipitationRefreshException;
use App\Http\Requests\Admin\UpdateKnmiSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\KnmiForecastOperationService;
use App\Services\KnmiPrecipitationRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminKnmiController extends Controller
{
    public function __construct(
        private readonly KnmiForecastOperationService $operations,
        private readonly KnmiPrecipitationRefreshService $precipitationRefresh,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->operations->status());
    }

    public function update(UpdateKnmiSettingsRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);

        /** @var array{open_data_api_key?: string, edr_api_key?: string} $keys */
        $keys = $request->validated();

        return ApiResponse::success($this->operations->updateKeys($keys, $actor, $request));
    }

    public function refresh(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        try {
            $operation = $this->operations->requestRefresh($actor, $request);
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
            $this->precipitationRefresh->request($actor, $request);
        } catch (KnmiPrecipitationRefreshException $exception) {
            return ApiResponse::error('knmi_precipitation_refresh_conflict', $exception->getMessage(), 409);
        }

        return ApiResponse::success(['requested' => true], 202);
    }
}
