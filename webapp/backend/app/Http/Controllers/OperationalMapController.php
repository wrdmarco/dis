<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\OperationalMapService;
use Illuminate\Http\JsonResponse;

final class OperationalMapController extends Controller
{
    public function __construct(private readonly OperationalMapService $service) {}

    public function layers(): JsonResponse
    {
        return ApiResponse::success($this->service->layers());
    }
}
