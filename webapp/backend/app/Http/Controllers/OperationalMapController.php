<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\OperationalMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperationalMapController extends Controller
{
    public function __construct(private readonly OperationalMapService $service) {}

    public function layers(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->layers(
            includePilotHomes: $request->user()->hasPermission('operational-map.pilot-homes.view'),
        ));
    }
}
