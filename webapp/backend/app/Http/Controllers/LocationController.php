<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LocationController extends Controller
{
    public function __construct(private readonly LocationService $service) {}

    public function consent(Request $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success($this->service->consent($incident, $request->user()), 201);
    }

    public function revoke(Request $request, Incident $incident): JsonResponse
    {
        $this->service->revoke($incident, $request->user());

        return ApiResponse::success(null, 204);
    }

    public function update(Request $request, Incident $incident): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        return ApiResponse::success($this->service->updateLocation($incident, $request->user(), $data), 201);
    }
}

