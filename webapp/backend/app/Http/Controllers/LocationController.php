<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LocationController extends Controller
{
    public function __construct(private readonly LocationService $service) {}

    public function consent(Request $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success($this->service->consent($incident, $request->user()), 201);
    }

    public function revoke(Request $request, Incident $incident): Response
    {
        $this->service->revoke($incident, $request->user());

        return response()->noContent();
    }

    public function update(Request $request, Incident $incident): Response
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $this->service->updateLocation($incident, $request->user(), $data);

        return response()->noContent();
    }
}
