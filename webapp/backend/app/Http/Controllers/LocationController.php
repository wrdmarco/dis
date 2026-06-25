<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
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

    public function liveLocations(Incident $incident): JsonResponse
    {
        $consents = LocationSharingConsent::query()
            ->with('user')
            ->where('incident_id', $incident->id)
            ->where('is_active', true)
            ->get();

        $latestLocations = LocationUpdate::query()
            ->where('incident_id', $incident->id)
            ->whereIn('user_id', $consents->pluck('user_id'))
            ->orderByDesc('recorded_at')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        return ApiResponse::success($consents
            ->map(function (LocationSharingConsent $consent) use ($latestLocations): array {
                $location = $latestLocations->get($consent->user_id);

                return [
                    'user_id' => $consent->user_id,
                    'user' => $consent->user === null ? null : [
                        'id' => $consent->user->id,
                        'name' => $consent->user->name,
                        'email' => $consent->user->email,
                    ],
                    'latitude' => $location?->latitude,
                    'longitude' => $location?->longitude,
                    'accuracy_meters' => $location?->accuracy_meters,
                    'recorded_at' => $location?->recorded_at?->toIso8601String(),
                ];
            })
            ->filter(fn (array $location): bool => $location['latitude'] !== null && $location['longitude'] !== null)
            ->values());
    }
}
