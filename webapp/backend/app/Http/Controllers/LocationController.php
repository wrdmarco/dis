<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
use App\Models\User;
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

    public function decline(Request $request, Incident $incident): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        return ApiResponse::success($this->service->decline($incident, $request->user(), $data['reason'] ?? null));
    }

    public function requestSharing(Request $request, Incident $incident): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'ulid', 'exists:users,id'],
        ]);

        return ApiResponse::success($this->service->requestSharing(
            $incident,
            User::query()->findOrFail($data['user_id']),
            $request->user(),
        ));
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
        if ($this->service->isClosedForLocationSharing($incident)) {
            return ApiResponse::success([]);
        }

        $acceptedRecipients = $incident->dispatchRequests()
            ->whereIn('status', ['sent', 'escalated'])
            ->with(['recipients.user'])
            ->latest()
            ->get()
            ->flatMap->recipients
            ->filter(fn ($recipient): bool => $recipient->response_status === 'accepted')
            ->unique('user_id')
            ->values();

        $acceptedUserIds = $acceptedRecipients->pluck('user_id')->unique()->values();
        $activeConsentUserIds = LocationSharingConsent::query()
            ->where('incident_id', $incident->id)
            ->where('is_active', true)
            ->pluck('user_id');
        $locationUserIds = $acceptedUserIds
            ->merge($activeConsentUserIds)
            ->unique()
            ->values();

        $consents = LocationSharingConsent::query()
            ->with('user')
            ->where('incident_id', $incident->id)
            ->whereIn('user_id', $locationUserIds)
            ->get();

        $latestLocations = LocationUpdate::query()
            ->with('user')
            ->where('incident_id', $incident->id)
            ->whereIn('user_id', $locationUserIds)
            ->orderByDesc('recorded_at')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        $acceptedRecipientsByUser = $acceptedRecipients->keyBy('user_id');
        $consentsByUser = $consents->keyBy('user_id');

        return ApiResponse::success($locationUserIds
            ->map(function (string $userId) use ($acceptedRecipientsByUser, $consentsByUser, $latestLocations, $incident): array {
                $recipient = $acceptedRecipientsByUser->get($userId);
                $consent = $consentsByUser->get($userId);
                $location = $latestLocations->get($userId);
                $user = $recipient?->user ?? $consent?->user ?? $location?->user;
                $sharingStatus = $this->locationSharingStatus($consent, $location);

                return [
                    'user_id' => $userId,
                    'user' => $user === null ? null : [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'sharing_status' => $sharingStatus,
                    'location_is_current' => $this->isCurrentLocation($location),
                    'consent_active' => (bool) ($consent?->is_active ?? false),
                    'requested_at' => $consent?->updated_at?->toIso8601String(),
                    'consented_at' => $consent?->is_active === true ? $consent->consented_at?->toIso8601String() : null,
                    'revoked_at' => $consent?->revoked_at?->toIso8601String(),
                    'declined_at' => $consent?->declined_at?->toIso8601String(),
                    'refusal_reason' => $consent?->refusal_reason,
                    'latitude' => $location?->latitude,
                    'longitude' => $location?->longitude,
                    'accuracy_meters' => $location?->accuracy_meters,
                    'recorded_at' => $location?->recorded_at?->toIso8601String(),
                    'eta_minutes' => $this->etaMinutes($incident, $location),
                ];
            })
            ->values());
    }

    private function locationSharingStatus(?LocationSharingConsent $consent, ?LocationUpdate $location): string
    {
        if ($consent?->declined_at !== null) {
            return 'declined';
        }

        if ($this->isCurrentLocation($location)) {
            return 'shared';
        }

        if ($location !== null) {
            return 'stale';
        }

        if ($consent?->is_active === true) {
            return 'consented';
        }

        if ($consent !== null && $consent->revoked_at === null) {
            return 'requested';
        }

        return 'not_requested';
    }

    private function isCurrentLocation(?LocationUpdate $location): bool
    {
        return $location?->recorded_at !== null && $location->recorded_at->greaterThanOrEqualTo(now()->subMinutes(5));
    }

    private function etaMinutes(Incident $incident, ?LocationUpdate $location): ?int
    {
        if ($location === null || $incident->latitude === null || $incident->longitude === null) {
            return null;
        }

        $distanceKm = $this->distanceKm((float) $location->latitude, (float) $location->longitude, (float) $incident->latitude, (float) $incident->longitude);

        return max(1, (int) ceil(($distanceKm / 60) * 60));
    }

    private function distanceKm(float $fromLat, float $fromLon, float $toLat, float $toLon): float
    {
        $earthRadiusKm = 6371;
        $latDelta = deg2rad($toLat - $fromLat);
        $lonDelta = deg2rad($toLon - $fromLon);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
