<?php

namespace App\Services;

use App\DTO\Routing\RouteGeometry;
use App\DTO\Routing\RoutePoint;
use App\Models\AvailabilityStatus;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallboard;
use App\Services\Routing\RouteGeometryService;
use App\Support\ApiDateTime;
use Illuminate\Support\Collection;

final class WallboardStateService
{
    private const RECENT_INCIDENT_LIMIT = 20;

    public function __construct(
        private readonly OperationalMapService $operationalMap,
        private readonly RouteGeometryService $routeGeometryService,
        private readonly WallboardDisplayService $displayService,
        private readonly WallboardFocusService $focusService,
        private readonly WallboardPlaylistResolver $playlistResolver,
        private readonly AvailabilityScheduleService $availabilityScheduleService,
        private readonly WallboardTickerService $tickerService,
    ) {}

    /** @return array<string, mixed> */
    public function state(Wallboard $wallboard): array
    {
        $configuration = $this->playlistResolver->resolve($wallboard);
        $activeAlarm = $this->activeAlarm();
        $focus = $this->focusService->resolve($configuration);
        $transientAlert = $this->transientAlert();
        $display = $this->displayService->display($wallboard, $configuration, $activeAlarm !== null);
        $mapConfiguration = (array) $configuration['map'];
        $pages = collect((array) $configuration['pages']);
        $hasMapPage = $pages->contains(fn (mixed $page): bool => is_array($page)
            && (string) ($page['type'] ?? '') === 'map');
        $incidentPages = $pages
            ->filter(fn (mixed $page): bool => is_array($page)
                && in_array((string) ($page['type'] ?? ''), ['incident_list', 'summary'], true));
        $summaryPages = $incidentPages
            ->filter(fn (array $page): bool => (string) ($page['type'] ?? '') === 'summary');
        $showsOperationalSummary = $summaryPages->isNotEmpty()
            || ($hasMapPage && ($mapConfiguration['show_summary'] ?? false) === true);
        $needsIncidents = $showsOperationalSummary
            || ($hasMapPage && (
                ($mapConfiguration['show_active_incidents'] ?? false) === true
                || ($mapConfiguration['show_live_locations'] ?? false) === true
            ))
            || $incidentPages->isNotEmpty();
        $incidents = $needsIncidents
            ? $this->activeIncidents()
            : collect();
        $layers = ($hasMapPage && (($mapConfiguration['show_command_centers'] ?? false) === true
            || ($mapConfiguration['show_historical_incidents'] ?? false) === true)
        )
                ? $this->operationalMap->layers(
                    includePilotHomes: false,
                    includeCommandCenters: (bool) ($mapConfiguration['show_command_centers'] ?? false),
                    includeHistoricalIncidents: (bool) ($mapConfiguration['show_historical_incidents'] ?? false),
                    includeTestIncidents: false,
                )
                : ['command_centers' => [], 'historical_incidents' => [], 'pilot_homes' => []];

        return [
            'generated_at' => ApiDateTime::now(),
            'wallboard' => [
                'id' => (string) $wallboard->id,
                'name' => (string) $wallboard->name,
                'layout' => (string) $wallboard->layout,
                'display_profile' => (string) $wallboard->display_profile,
                'configuration' => $configuration,
                'config_version' => (int) $wallboard->config_version,
                'control_version' => (int) $wallboard->control_version,
                'display' => $display,
                'updated_at' => ApiDateTime::dateTime($wallboard->updated_at),
            ],
            'operational_summary' => [
                'pilot_availability' => $showsOperationalSummary
                    ? $this->pilotAvailability()
                    : ['available' => 0, 'total' => 0],
                'active_alarm' => $activeAlarm,
                'recent_incidents' => $showsOperationalSummary
                    ? $this->recentIncidents()
                    : [],
                'focus' => $focus,
                'transient_alert' => $transientAlert,
            ],
            'ticker' => [
                'items' => $this->tickerService->items((array) $configuration['ticker']),
            ],
            'map' => [
                'incidents' => $incidents->map(fn (Incident $incident): array => $this->incidentPayload($incident))->values()->all(),
                'command_centers' => $hasMapPage && ($mapConfiguration['show_command_centers'] ?? false) === true
                    ? $layers['command_centers']
                    : [],
                'historical_incidents' => $hasMapPage && ($mapConfiguration['show_historical_incidents'] ?? false) === true
                    ? $layers['historical_incidents']
                    : [],
                'live_locations' => $hasMapPage && ($mapConfiguration['show_live_locations'] ?? false) === true
                    ? $this->liveLocations($incidents, (bool) ($mapConfiguration['show_routes'] ?? false))
                    : [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function control(Wallboard $wallboard): array
    {
        $configuration = $this->playlistResolver->resolve($wallboard);
        $activeAlarm = $this->activeAlarm();
        $focus = $this->focusService->resolve($configuration);

        return [
            'generated_at' => ApiDateTime::now(),
            'display_profile' => (string) $wallboard->display_profile,
            'config_version' => (int) $wallboard->config_version,
            'control_version' => (int) $wallboard->control_version,
            'display' => $this->displayService->display($wallboard, $configuration, $activeAlarm !== null),
            'focus' => $focus,
            'transient_alert' => $this->transientAlert(),
            'poll_after_seconds' => 2,
        ];
    }

    /** @return array{available: int, total: int} */
    private function pilotAvailability(): array
    {
        $pilots = User::query()
            ->where('account_status', 'active')
            ->whereHas('teams', fn ($teams) => $teams->where('teams.code', 'OCP'))
            ->whereHas('roles', fn ($roles) => $roles->where('roles.name', 'operator-pilot'))
            ->with(['statuses' => fn ($statuses) => $statuses->latestPerUser()])
            ->get(['id', 'push_enabled']);
        $scheduledAvailability = $this->availabilityScheduleService->availabilityByUser($pilots);
        $available = $pilots->filter(function (User $pilot) use ($scheduledAvailability): bool {
            $latestStatus = $pilot->statuses->first();

            return (bool) $pilot->push_enabled
                && ($latestStatus === null || (bool) $latestStatus->is_available)
                && ($scheduledAvailability[(string) $pilot->id] ?? true);
        })->count();

        return ['available' => $available, 'total' => $pilots->count()];
    }

    /**
     * @return array{id: string, reference: string, title: string, status: string, priority: string, location_label: string|null, opened_at: string|null}|null
     */
    private function activeAlarm(): ?array
    {
        $incident = Incident::query()
            ->whereIn('status', ['dispatching', 'in_progress'])
            ->where('is_test', false)
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->first(['id', 'reference', 'title', 'status', 'priority', 'location_label', 'opened_at']);
        if (! $incident instanceof Incident) {
            return null;
        }

        return [
            'id' => (string) $incident->id,
            'reference' => (string) $incident->reference,
            'title' => (string) $incident->title,
            'status' => (string) $incident->status,
            'priority' => (string) $incident->priority,
            'location_label' => $incident->location_label,
            'opened_at' => ApiDateTime::dateTime($incident->opened_at),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentIncidents(): array
    {
        return Incident::query()
            ->whereIn('status', ['resolved', 'cancelled'])
            ->where('is_test', false)
            ->orderByRaw('case when closed_at is null then 1 else 0 end')
            ->orderByDesc('closed_at')
            ->orderByDesc('updated_at')
            ->limit(self::RECENT_INCIDENT_LIMIT)
            ->get(['id', 'reference', 'title', 'status', 'priority', 'is_test', 'location_label', 'closed_at'])
            ->map(fn (Incident $incident): array => [
                'id' => (string) $incident->id,
                'reference' => (string) $incident->reference,
                'title' => (string) $incident->title,
                'status' => (string) $incident->status,
                'priority' => (string) $incident->priority,
                'is_test' => (bool) $incident->is_test,
                'location_label' => $incident->location_label,
                'closed_at' => ApiDateTime::dateTime($incident->closed_at),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function transientAlert(): ?array
    {
        $dispatch = DispatchRequest::query()
            ->with('incident:id,reference,title,priority,is_test,location_label')
            ->whereIn('status', ['sent', 'escalated'])
            ->whereNotNull('sent_at')
            ->whereHas('incident')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first(['id', 'incident_id', 'status', 'priority', 'sent_at']);
        if (! $dispatch instanceof DispatchRequest
            || ! $dispatch->incident instanceof Incident
            || $dispatch->sent_at === null) {
            return null;
        }

        $receivedAt = ApiDateTime::localWallClock($dispatch->sent_at);
        if ($receivedAt === null) {
            return null;
        }
        $expiresAt = $receivedAt->addSeconds(max(
            1,
            SystemSetting::integer('dispatch.response_timeout_seconds', 300),
        ));
        $now = ApiDateTime::localWallClock(now());
        if ($now === null || $expiresAt->lessThanOrEqualTo($now)) {
            return null;
        }
        $incident = $dispatch->incident;

        return [
            'dispatch_id' => (string) $dispatch->id,
            'incident_id' => (string) $incident->id,
            'reference' => (string) $incident->reference,
            'title' => (string) $incident->title,
            'priority' => (string) ($incident->priority ?: $dispatch->priority),
            'location_label' => $incident->location_label,
            'received_at' => ApiDateTime::dateTime($receivedAt),
            'expires_at' => ApiDateTime::dateTime($expiresAt),
            'is_test' => (bool) $incident->is_test,
        ];
    }

    /** @return Collection<int, Incident> */
    private function activeIncidents(): Collection
    {
        return Incident::query()
            ->whereIn('status', ['active', 'dispatching', 'in_progress'])
            ->where('is_test', false)
            ->latest('opened_at')
            ->limit(100)
            ->get([
                'id',
                'reference',
                'title',
                'status',
                'priority',
                'is_test',
                'location_label',
                'latitude',
                'longitude',
                'opened_at',
            ]);
    }

    /** @return array<string, mixed> */
    private function incidentPayload(Incident $incident): array
    {
        return [
            'id' => (string) $incident->id,
            'reference' => (string) $incident->reference,
            'title' => (string) $incident->title,
            'status' => (string) $incident->status,
            'priority' => (string) $incident->priority,
            'is_test' => (bool) $incident->is_test,
            'location_label' => $incident->location_label,
            'latitude' => $incident->latitude === null ? null : (float) $incident->latitude,
            'longitude' => $incident->longitude === null ? null : (float) $incident->longitude,
            'opened_at' => ApiDateTime::dateTime($incident->opened_at),
        ];
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<array<string, mixed>>
     */
    private function liveLocations(Collection $incidents, bool $includeRoutes): array
    {
        $incidentIds = $incidents->pluck('id')->map(fn ($id): string => (string) $id)->values();
        if ($incidentIds->isEmpty()) {
            return [];
        }

        $acceptedPairs = DispatchRecipient::query()
            ->join('dispatch_requests', 'dispatch_requests.id', '=', 'dispatch_recipients.dispatch_request_id')
            ->whereIn('dispatch_requests.incident_id', $incidentIds)
            ->whereIn('dispatch_requests.status', ['sent', 'escalated'])
            ->where('dispatch_recipients.response_status', 'accepted')
            ->get([
                'dispatch_requests.incident_id as incident_id',
                'dispatch_recipients.user_id as user_id',
            ])
            ->unique(fn ($row): string => (string) $row->incident_id.'|'.(string) $row->user_id)
            ->values();
        if ($acceptedPairs->isEmpty()) {
            return [];
        }

        $userIds = $acceptedPairs->pluck('user_id')->map(fn ($id): string => (string) $id)->unique()->values();
        $onSceneUserIds = AvailabilityStatus::query()
            ->latestPerUser()
            ->whereIn('user_id', $userIds)
            ->where('status', 'on_scene')
            ->pluck('user_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $onSceneLookup = array_fill_keys($onSceneUserIds, true);
        $acceptedPairLookup = $acceptedPairs
            ->reject(fn ($row): bool => isset($onSceneLookup[(string) $row->user_id]))
            ->mapWithKeys(fn ($row): array => [
                (string) $row->incident_id.'|'.(string) $row->user_id => true,
            ]);
        if ($acceptedPairLookup->isEmpty()) {
            return [];
        }

        $consents = LocationSharingConsent::query()
            ->whereIn('incident_id', $incidentIds)
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->get(['incident_id', 'user_id', 'state_version', 'consented_at'])
            ->filter(fn (LocationSharingConsent $consent): bool => $acceptedPairLookup->has(
                (string) $consent->incident_id.'|'.(string) $consent->user_id,
            ))
            ->keyBy(fn (LocationSharingConsent $consent): string => (string) $consent->incident_id.'|'.(string) $consent->user_id);
        if ($consents->isEmpty()) {
            return [];
        }

        $latestLocationUpperBound = now()->addMinutes(2);
        $locations = LocationUpdate::query()
            ->whereIn('incident_id', $incidentIds)
            ->whereIn('user_id', $userIds)
            ->where('recorded_at', '<=', $latestLocationUpperBound)
            ->where('created_at', '<=', $latestLocationUpperBound)
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
            ->get([
                'id',
                'incident_id',
                'user_id',
                'consent_state_version',
                'latitude',
                'longitude',
                'accuracy_meters',
                'recorded_at',
                'created_at',
            ])
            ->filter(function (LocationUpdate $location) use ($consents): bool {
                $key = (string) $location->incident_id.'|'.(string) $location->user_id;
                $consent = $consents->get($key);
                $createdAt = ApiDateTime::localWallClock($location->created_at);
                $consentedAt = ApiDateTime::localWallClock($consent?->consented_at);

                return $consent instanceof LocationSharingConsent
                    && (int) $location->consent_state_version === (int) $consent->state_version
                    && $consentedAt !== null
                    && $createdAt?->greaterThanOrEqualTo($consentedAt) === true
                    && $this->isCurrentLocation($location);
            })
            ->values();
        if ($locations->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $locations->pluck('user_id')->unique())
            ->get(['id', 'name'])
            ->keyBy('id');
        $incidentsById = $incidents->keyBy('id');
        $routes = $includeRoutes
            ? $this->routesForLocations($locations, $incidentsById)
            : [];

        return $locations
            ->map(function (LocationUpdate $location) use ($users, $routes): array {
                $key = (string) $location->incident_id.'|'.(string) $location->user_id;
                $route = $routes[$key] ?? null;
                $user = $users->get($location->user_id);

                return [
                    'incident_id' => (string) $location->incident_id,
                    'user_id' => (string) $location->user_id,
                    'user' => $user instanceof User ? ['id' => (string) $user->id, 'name' => (string) $user->name] : null,
                    'sharing_status' => 'shared',
                    'location_is_current' => true,
                    'latitude' => (float) $location->latitude,
                    'longitude' => (float) $location->longitude,
                    'accuracy_meters' => $location->accuracy_meters === null ? null : (float) $location->accuracy_meters,
                    'recorded_at' => ApiDateTime::dateTime($location->recorded_at),
                    'eta_minutes' => $route === null ? null : max(1, (int) ceil($route->duration / 60)),
                    'eta_source' => $route === null ? 'unknown' : 'navigation',
                    'route' => $route?->toArray(),
                ];
            })
            ->values()
            ->all();
    }

    private function isCurrentLocation(LocationUpdate $location): bool
    {
        $recordedAt = ApiDateTime::localWallClock($location->recorded_at);
        $createdAt = ApiDateTime::localWallClock($location->created_at);
        $now = now();

        return $recordedAt !== null
            && $createdAt !== null
            && $recordedAt->lessThanOrEqualTo($createdAt->copy()->addMinutes(2))
            && $recordedAt->betweenIncluded($now->copy()->subMinutes(5), $now->copy()->addMinutes(2))
            && $createdAt->betweenIncluded($now->copy()->subMinutes(5), $now->copy()->addMinutes(2));
    }

    /**
     * @param  Collection<int, LocationUpdate>  $locations
     * @param  Collection<string, Incident>  $incidents
     * @return array<string, RouteGeometry>
     */
    private function routesForLocations(Collection $locations, Collection $incidents): array
    {
        $routes = [];
        foreach ($locations->groupBy('incident_id') as $incidentId => $incidentLocations) {
            $incident = $incidents->get($incidentId);
            if (! $incident instanceof Incident) {
                continue;
            }
            $destination = $this->routePoint($incident->latitude, $incident->longitude);
            if ($destination === null) {
                continue;
            }

            $origins = [];
            foreach ($incidentLocations as $location) {
                $origin = $this->routePoint($location->latitude, $location->longitude);
                if ($origin !== null) {
                    $origins[(string) $location->user_id] = $origin;
                }
            }
            foreach ($this->routeGeometryService->routesTo($origins, $destination) as $userId => $route) {
                $routes[(string) $incidentId.'|'.(string) $userId] = $route;
            }
        }

        return $routes;
    }

    private function routePoint(mixed $latitude, mixed $longitude): ?RoutePoint
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if (! is_finite($latitude) || $latitude < -90 || $latitude > 90
            || ! is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return new RoutePoint($latitude, $longitude);
    }
}
