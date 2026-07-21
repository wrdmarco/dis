<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForecastLocationRequest;
use App\Http\Responses\ApiResponse;
use App\Services\OperationalWeatherService;
use App\Services\WallboardForecastService;
use Illuminate\Http\JsonResponse;

final class OperationalForecastController extends Controller
{
    public function weather(
        ForecastLocationRequest $request,
        OperationalWeatherService $weather,
    ): JsonResponse {
        return ApiResponse::success($weather->forecastForOptions($request->forecastOptions()));
    }

    public function uav(
        ForecastLocationRequest $request,
        WallboardForecastService $forecast,
    ): JsonResponse {
        return ApiResponse::success($forecast->forecastForOptions($request->forecastOptions()));
    }
}
