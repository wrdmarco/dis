<?php

namespace App\Http\Controllers;

use App\DTO\Routing\RouteEstimate;
use App\DTO\Routing\RouteGeometry;
use App\DTO\Routing\RoutePoint;
use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
use App\Models\User;
use App\Services\IncidentAccessService;
use App\Services\LocationService;
use App\Services\Routing\RouteGeometryService;
use App\Services\Routing\RoutingService;
use App\Support\ApiDateTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

final class LocationController extends Controller
{
    public function __construct(
        private readonly LocationService $service,
        private readonly IncidentAccessService $access,
        private readonly RoutingService $routingService,
        private readonly RouteGeometryService $routeGeometryService,
    ) {}

    public function consent(Request $request, Incident $incident): JsonResponse
    {
        $this->access->assertCanViewIncident($request->user(), $incident);

        return ApiResponse::success($this->service->consent($incident, $request->user()), 201);
    }

    public function revoke(Request $request, Incident $incident): Response
    {
        $this->access->assertCanViewIncident($request->user(), $incident);
        $this->service->revoke($incident, $request->user());

        return response()->noContent();
    }

    public function decline(Request $request, Incident $incident): JsonResponse
    {
        $this->access->assertCanViewIncident($request->user(), $incident);
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
        $this->access->assertCanViewIncident($request->user(), $incident);
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'recorded_at' => ['nullable', 'bail', 'date', 'before_or_equal:'.now()->addMinutes(2)->toIso8601String()],
        ]);

        $this->service->updateLocation($incident, $request->user(), $data);

        return response()->noContent();
    }

    public function liveLocations(Request $request, Incident $incident): JsonResponse
    {
        $this->access->assertCanViewIncident($request->user(), $incident);
        $query = $request->validate([
            'include_routes' => ['sometimes', 'boolean'],
        ]);
        $includeRoutes = (bool) ($query['include_routes'] ?? false);
        if ($includeRoutes && ! $request->user()->hasPermission('operational-map.view')) {
            throw new AuthorizationException('Viewing live pilot routes requires operational map access.');
        }
        if ($this->service->isClosedForLocationSharing($incident)) {
            return ApiResponse::success([]);
        }

        $acceptedRecipients = $incident->dispatchRequests()
            ->whereIn('status', ['sent', 'escalated'])
            ->with(['recipients.user.statuses' => fn ($statuses) => $statuses->latestPerUser()])
            ->latest()
            ->get()
            ->flatMap->recipients
            ->filter(fn ($recipient): bool => $recipient->response_status === 'accepted')
            // LocationService normally revokes consent on arrival. This
            // server-side projection also closes the small race between the
            // status write and that revocation without an N+1 status query.
            ->reject(fn ($recipient): bool => $recipient->user?->statuses->first()?->status === 'on_scene')
            ->unique('user_id')
            ->values();

        $acceptedUserIds = $acceptedRecipients->pluck('user_id')->unique()->values();
        $activeConsentUserIds = LocationSharingConsent::query()
            ->where('incident_id', $incident->id)
            ->where('is_active', true)
            ->whereIn('user_id', $acceptedUserIds)
            ->pluck('user_id');
        // A consent never grants incident participation by itself. Once a
        // recipient declines the dispatch, it must disappear immediately even
        // if an older client has not yet processed the consent revocation.
        $locationUserIds = $acceptedUserIds;
        if ($request->user()->isOperatorClient()) {
            $locationUserIds = $locationUserIds->filter(fn (string $userId): bool => $userId === (string) $request->user()->id)->values();
        }
        $activeLocationUserIds = $locationUserIds->intersect($activeConsentUserIds)->values();

        $consents = LocationSharingConsent::query()
            ->with('user')
            ->where('incident_id', $incident->id)
            ->whereIn('user_id', $locationUserIds)
            ->get();
        $consentsByUser = $consents->keyBy('user_id');

        $latestLocationUpperBound = now()->addMinutes(2);
        $latestLocations = LocationUpdate::query()
            ->with('user')
            ->where('incident_id', $incident->id)
            ->whereIn('user_id', $activeLocationUserIds)
            ->where('recorded_at', '<=', $latestLocationUpperBound)
            ->where('created_at', '<=', $latestLocationUpperBound)
            // Select at most one row per user in SQL. Server receipt order is
            // authoritative; loading the complete 30-day location history on
            // every poll would make incident ETA degrade as history grows.
            ->whereNotExists(function ($newerLocation) use ($latestLocationUpperBound): void {
                $newerLocation
                    ->selectRaw('1')
                    ->from('location_updates as newer_location')
                    ->whereColumn('newer_location.incident_id', 'location_updates.incident_id')
                    ->whereColumn('newer_location.user_id', 'location_updates.user_id')
                    ->where('newer_location.recorded_at', '<=', $latestLocationUpperBound)
                    ->where('newer_location.created_at', '<=', $latestLocationUpperBound)
                    ->where(function ($newerReceipt): void {
                        $newerReceipt
                            ->whereColumn('newer_location.created_at', '>', 'location_updates.created_at')
                            ->orWhere(function ($sameReceipt): void {
                                $sameReceipt
                                    ->whereColumn('newer_location.created_at', '=', 'location_updates.created_at')
                                    ->whereColumn('newer_location.id', '>', 'location_updates.id');
                            });
                    });
            })
            ->get()
            ->filter(function (LocationUpdate $location) use ($consentsByUser): bool {
                $consent = $consentsByUser->get($location->user_id);
                $createdAt = ApiDateTime::localWallClock($location->created_at);
                $consentedAt = ApiDateTime::localWallClock($consent?->consented_at);

                return $this->isPlausiblyRecordedLocation($location)
                    && $consent?->is_active === true
                    && (int) $location->consent_state_version === (int) $consent->state_version
                    && $consentedAt !== null
                    // Server receipt time is authoritative across revoke and
                    // re-consent; an old client timestamp may never revive a
                    // coordinate received under the previous consent.
                    && $createdAt?->greaterThanOrEqualTo($consentedAt) === true;
            })
            ->keyBy('user_id');

        $acceptedRecipientsByUser = $acceptedRecipients->keyBy('user_id');
        $routeGeometries = $includeRoutes
            ? $this->liveRouteGeometries($incident, $latestLocations)
            : [];
        $routeEstimates = $includeRoutes
            ? $this->liveRouteEstimatesFromGeometries($incident, $latestLocations, $routeGeometries)
            : $this->liveRouteEstimates($incident, $latestLocations);
        $includeLegacyMobileUserFields = in_array($request->user()->currentClientType(), ['operator', 'admin'], true);

        return ApiResponse::success($locationUserIds
            ->map(function (string $userId) use ($acceptedRecipientsByUser, $consentsByUser, $latestLocations, $routeEstimates, $routeGeometries, $includeRoutes, $includeLegacyMobileUserFields): array {
                $recipient = $acceptedRecipientsByUser->get($userId);
                $consent = $consentsByUser->get($userId);
                $location = $latestLocations->get($userId);
                $user = $recipient?->user ?? $consent?->user ?? $location?->user;
                $sharingStatus = $this->locationSharingStatus($consent, $location);
                $locationIsCurrent = $this->isCurrentLocation($location);
                $estimate = $locationIsCurrent
                    ? ($routeEstimates[$userId] ?? RouteEstimate::unknown())
                    : RouteEstimate::unknown();

                $payload = [
                    'user_id' => $userId,
                    'user' => $user === null ? null : $this->locationUserIdentity($user, $includeLegacyMobileUserFields),
                    'sharing_status' => $sharingStatus,
                    'location_is_current' => $locationIsCurrent,
                    'consent_active' => (bool) ($consent?->is_active ?? false),
                    'requested_at' => ApiDateTime::dateTime($consent?->updated_at),
                    'consented_at' => $consent?->is_active === true ? ApiDateTime::dateTime($consent->consented_at) : null,
                    'revoked_at' => ApiDateTime::dateTime($consent?->revoked_at),
                    'declined_at' => ApiDateTime::dateTime($consent?->declined_at),
                    'refusal_reason' => $consent?->refusal_reason,
                    'latitude' => $location?->latitude,
                    'longitude' => $location?->longitude,
                    'accuracy_meters' => $location?->accuracy_meters,
                    'recorded_at' => ApiDateTime::dateTime($location?->recorded_at),
                    'eta_minutes' => $this->etaMinutes($estimate),
                    'eta_source' => $estimate->source->value,
                ];

                if ($includeRoutes) {
                    $payload['route'] = ($routeGeometries[$userId] ?? null)?->toArray();
                }

                return $payload;
            })
            ->values());
    }

    /**
     * @return array<string, mixed>
     */
    private function locationUserIdentity(User $user, bool $includeLegacyMobileFields): array
    {
        $identity = ['id' => $user->id, 'name' => $user->name];
        if (! $includeLegacyMobileFields) {
            return $identity;
        }

        // Deployed Android/iOS builds decoded this nested object as AuthUser.
        // Empty relation arrays preserve that wire contract without exposing
        // role and permission internals. Operators only receive their own row.
        return $identity + [
            'email' => $user->email,
            'roles' => [],
            'teams' => [],
        ];
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
        if ($location?->recorded_at === null) {
            return false;
        }

        $recordedAt = ApiDateTime::localWallClock($location->recorded_at);
        $createdAt = ApiDateTime::localWallClock($location->created_at);
        $now = now();

        return $this->isPlausiblyRecordedLocation($location)
            && $recordedAt?->betweenIncluded($now->copy()->subMinutes(5), $now->copy()->addMinutes(2)) === true
            && $createdAt?->betweenIncluded($now->copy()->subMinutes(5), $now->copy()->addMinutes(2)) === true;
    }

    private function isPlausiblyRecordedLocation(LocationUpdate $location): bool
    {
        if ($location->recorded_at === null || $location->created_at === null) {
            return false;
        }

        $recordedAt = ApiDateTime::localWallClock($location->recorded_at);
        $createdAt = ApiDateTime::localWallClock($location->created_at);

        return $recordedAt?->lessThanOrEqualTo($createdAt?->addMinutes(2)) === true;
    }

    /**
     * @param  Collection<string, LocationUpdate>  $latestLocations
     * @return array<string, RouteEstimate>
     */
    private function liveRouteEstimates(Incident $incident, Collection $latestLocations): array
    {
        $destination = $this->routePoint($incident->latitude, $incident->longitude);
        if ($destination === null) {
            return [];
        }

        $origins = [];
        foreach ($latestLocations as $userId => $location) {
            if (! $this->isCurrentLocation($location)) {
                continue;
            }

            $origin = $this->routePoint($location->latitude, $location->longitude);
            if ($origin !== null) {
                $origins[(string) $userId] = $origin;
            }
        }

        return $this->routingService->routesTo($origins, $destination);
    }

    /**
     * @param  Collection<string, LocationUpdate>  $latestLocations
     * @return array<string, RouteGeometry>
     */
    private function liveRouteGeometries(Incident $incident, Collection $latestLocations): array
    {
        $destination = $this->routePoint($incident->latitude, $incident->longitude);
        if ($destination === null) {
            return [];
        }

        $origins = [];
        foreach ($latestLocations as $userId => $location) {
            if (! $this->isCurrentLocation($location)) {
                continue;
            }

            $origin = $this->routePoint($location->latitude, $location->longitude);
            if ($origin !== null) {
                $origins[(string) $userId] = $origin;
            }
        }

        return $this->routeGeometryService->routesTo($origins, $destination);
    }

    /**
     * Avoid a second OSRM table request during an opted-in map poll. Successful
     * geometry responses already contain navigation duration and distance;
     * missing routes receive the same explicit provider-free fallback used by
     * the normal ETA flow.
     *
     * @param  Collection<string, LocationUpdate>  $latestLocations
     * @param  array<string, RouteGeometry>  $routeGeometries
     * @return array<string, RouteEstimate>
     */
    private function liveRouteEstimatesFromGeometries(
        Incident $incident,
        Collection $latestLocations,
        array $routeGeometries,
    ): array {
        $destination = $this->routePoint($incident->latitude, $incident->longitude);
        if ($destination === null) {
            return [];
        }

        $origins = [];
        foreach ($latestLocations as $userId => $location) {
            if (! $this->isCurrentLocation($location)) {
                continue;
            }

            $origin = $this->routePoint($location->latitude, $location->longitude);
            if ($origin !== null) {
                $origins[(string) $userId] = $origin;
            }
        }

        $estimates = $this->routingService->fallbackRoutesTo($origins, $destination);
        foreach ($routeGeometries as $userId => $route) {
            if (array_key_exists($userId, $origins)) {
                $estimates[$userId] = RouteEstimate::navigation($route->duration, $route->distance);
            }
        }

        return $estimates;
    }

    private function etaMinutes(RouteEstimate $estimate): ?int
    {
        return $estimate->duration === null
            ? null
            : max(1, (int) ceil($estimate->duration / 60));
    }

    private function routePoint(mixed $latitudeValue, mixed $longitudeValue): ?RoutePoint
    {
        $latitude = $this->coordinate($latitudeValue, -90, 90);
        $longitude = $this->coordinate($longitudeValue, -180, 180);

        return $latitude === null || $longitude === null
            ? null
            : new RoutePoint($latitude, $longitude);
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;
        if (! is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
            return null;
        }

        return $coordinate;
    }
}
