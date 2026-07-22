<?php

namespace App\Http\Controllers;

use App\Contracts\OperationalRadarProvider;
use App\Http\Requests\ForecastLocationRequest;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\OperationalRadarResponse;
use App\Services\OperationalWeatherService;
use App\Services\WallboardForecastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OperationalForecastController extends Controller
{
    public function weather(
        ForecastLocationRequest $request,
        OperationalWeatherService $weather,
        OperationalRadarProvider $radar,
    ): JsonResponse {
        return ApiResponse::success([
            ...$weather->forecastForOptions($request->forecastOptions()),
            'radar' => $radar->metadata(),
        ]);
    }

    public function uav(
        ForecastLocationRequest $request,
        WallboardForecastService $forecast,
    ): JsonResponse {
        return ApiResponse::success($forecast->forecastForOptions($request->forecastOptions()));
    }

    public function radarAtlas(
        Request $request,
        string $kind,
        string $snapshot,
        OperationalRadarProvider $radar,
    ): Response|StreamedResponse {
        $content = $radar->file($kind, $snapshot);
        abort_if($content === null, 404);

        return OperationalRadarResponse::make($request, $content);
    }
}
