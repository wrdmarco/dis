<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\AeretPreflightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AeretPreflightController extends Controller
{
    public function nearby(Request $request, AeretPreflightService $service): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'between:1000,25000'],
        ]);

        return ApiResponse::success($service->nearby(
            (float) $data['latitude'],
            (float) $data['longitude'],
            (int) ($data['radius_m'] ?? 5000),
        ));
    }
}
