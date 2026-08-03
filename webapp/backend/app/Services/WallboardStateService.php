<?php

namespace App\Services;

use App\Contracts\OperationalRadarProvider;
use App\DTO\Routing\RouteGeometry;
use App\DTO\Routing\RoutePoint;
use App\Models\AvailabilityStatus;
use App\Models\Deployment;
use App\Models\DeploymentPilotAssignment;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Services\Routing\RouteGeometryService;
use App\Support\ApiDateTime;
use App\Support\OperationalRadarContent;
use App\Support\WallboardConfiguration;
use Illuminate\Support\Collection;
use Throwable;

final class WallboardStateService
{
    private const RECENT_DEPLOYMENT_LIMIT = 20;

    public function __construct(
        private readonly OperationalMapService $operationalMap,
        private readonly RouteGeometryService $routeGeometryService,
        private readonly WallboardDisplayService $displayService,
        private readonly WallboardFocusService $focusService,
        private readonly WallboardPlaylistResolver $playlistResolver,
        private readonly WallboardKpiService $kpiService,
        private readonly WallboardContentSnapshotService $contentSnapshots,
        private readonly WallboardMediaStateService $mediaStateService,
        private readonly WallboardMaintenanceNoticeService $maintenanceNoticeService,
        private readonly WallboardForecastService $forecastService,
        private readonly WallboardCalendarService $calendarService,
        private readonly WallboardDemoStateService $demoStateService,
        private readonly OperationalRadarProvider $radar,
    ) {}

    /** @return array<string, mixed> */
    public function state(Wallboard $wallboard): array
    {
        $base = $this->playlistResolver->resolveRuntime($wallboard, false);
        if ($base['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO) {
            return $this->demoState($wallboard, $base);
        }

        $activeAlarm = $this->activeAlarm();
        $resolved = $this->playlistResolver->resolveRuntime(
            $wallboard,
            $this->activeDeploymentExists($activeAlarm),
        );
        $configuration = $resolved['configuration'];
        $runtime = $this->runtime($wallboard, $configuration, $activeAlarm);
        $static = $this->staticContent(
            $wallboard,
            $configuration,
            $resolved['playlist_id'],
            $resolved['playlist_version'],
            $resolved['active_deployment_playlist'],
            $resolved['data_mode'],
            $resolved['purpose'],
        );
        $news = $this->contentSnapshots->news($wallboard, $configuration, $resolved['playlist_id']);
        $ticker = $this->contentSnapshots->ticker($wallboard, $configuration, $resolved['playlist_id']);

        return [
            'generated_at' => $runtime['generated_at'],
            'maintenance' => $runtime['maintenance'],
            'wallboard' => [
                ...$static['wallboard'],
                'control_version' => (int) $wallboard->control_version,
                'refresh_version' => (int) $wallboard->refresh_version,
                'display' => $runtime['display'],
                'updated_at' => ApiDateTime::dateTime($wallboard->updated_at),
            ],
            'operational_summary' => $runtime['operational_summary'],
            'kpi' => $runtime['kpi'],
            'ticker' => ['items' => $ticker['items']],
            'news' => [
                'pages' => $news['pages'],
                'generated_at' => $news['generated_at'] ?? $runtime['generated_at'],
            ],
            'media' => $static['media'],
            'forecast' => ['pages' => $this->forecastService->pages($configuration)],
            'weather_radar' => $runtime['weather_radar'],
            'calendar' => $runtime['calendar'],
            'map' => $runtime['map'],
        ];
    }

    /** @return array<string, mixed> */
    public function live(Wallboard $wallboard): array
    {
        $base = $this->playlistResolver->resolveRuntime($wallboard, false);
        if ($base['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO) {
            $runtime = $this->demoStateService->runtime($wallboard, $base['configuration']);

            return [
                'generated_at' => $runtime['generated_at'],
                'maintenance' => $runtime['maintenance'],
                'operational_summary' => $runtime['operational_summary'],
                'kpi' => $runtime['kpi'],
                'weather_radar' => null,
                'calendar' => $runtime['calendar'],
                'map' => $runtime['map'],
            ];
        }

        $activeAlarm = $this->activeAlarm();
        $resolved = $this->playlistResolver->resolveRuntime(
            $wallboard,
            $this->activeDeploymentExists($activeAlarm),
        );
        $runtime = $this->runtime($wallboard, $resolved['configuration'], $activeAlarm);

        return [
            'generated_at' => $runtime['generated_at'],
            'maintenance' => $runtime['maintenance'],
            'operational_summary' => $runtime['operational_summary'],
            'kpi' => $runtime['kpi'],
            'weather_radar' => $runtime['weather_radar'],
            'calendar' => $runtime['calendar'],
            'map' => $runtime['map'],
        ];
    }

    /**
     * Build the live operational portion for an administrator preview. Current
     * operational data remains visible, while focus, transient alerts,
     * maintenance and deployment-driven page takeover are deliberately disabled.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function previewRuntime(Wallboard $wallboard, array $configuration): array
    {
        return $this->runtime(
            $wallboard,
            $configuration,
            $this->activeAlarm(),
            suppressTakeovers: true,
            wallboardAtlasUrls: false,
        );
    }

    public function weatherRadarAtlas(
        Wallboard $wallboard,
        string $kind,
        string $snapshot,
    ): ?OperationalRadarContent {
        if (! in_array($kind, WallboardConfiguration::WEATHER_RADAR_KINDS, true)) {
            return null;
        }

        $base = $this->playlistResolver->resolveRuntime($wallboard, false);
        if ($base['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO) {
            return null;
        }

        $authorized = $this->hasWeatherRadarKind($base['configuration'], $kind);
        if (! $authorized && $wallboard->active_deployment_playlist_id !== null) {
            // An immutable URL may be requested just after an alarm starts or
            // ends. Authorize the one assigned live alarm playlist as well as
            // the normal playlist, without widening access to any other
            // playlist or making authorization depend on deployment timing.
            $alarm = $this->playlistResolver->resolveRuntime($wallboard, true);
            $authorized = $alarm['active_deployment_playlist'] === true
                && $alarm['data_mode'] === WallboardPlaylist::DATA_MODE_LIVE
                && $this->hasWeatherRadarKind($alarm['configuration'], $kind);
        }
        if (! $authorized) {
            return null;
        }

        return $this->radar->file($kind, $snapshot);
    }

    /**
     * @param  array<string, mixed>|null  $configuration
     * @return array<string, mixed>
     */
    public function staticContent(
        Wallboard $wallboard,
        ?array $configuration = null,
        ?string $playlistId = null,
        ?int $playlistVersion = null,
        bool $activeDeploymentPlaylist = false,
        string $dataMode = WallboardPlaylist::DATA_MODE_LIVE,
        string $purpose = WallboardPlaylist::PURPOSE_NORMAL,
    ): array {
        if ($configuration === null) {
            $base = $this->playlistResolver->resolveRuntime($wallboard, false);
            if ($base['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO) {
                $resolved = $base;
            } else {
                $activeAlarm = $this->activeAlarm();
                $resolved = $this->playlistResolver->resolveRuntime(
                    $wallboard,
                    $this->activeDeploymentExists($activeAlarm),
                );
            }
            $configuration = $resolved['configuration'];
            $playlistId = $resolved['playlist_id'];
            $playlistVersion = $resolved['playlist_version'];
            $activeDeploymentPlaylist = $resolved['active_deployment_playlist'];
            $dataMode = $resolved['data_mode'];
            $purpose = $resolved['purpose'];
        }

        return [
            'wallboard' => [
                'id' => (string) $wallboard->id,
                'name' => (string) $wallboard->name,
                'layout' => (string) $wallboard->layout,
                'display_profile' => (string) $wallboard->display_profile,
                'configuration' => $configuration,
                'config_version' => (int) $wallboard->config_version,
                'runtime_playlist_id' => $playlistId,
                'runtime_playlist_version' => $playlistVersion ?? 0,
                'active_deployment_playlist' => $activeDeploymentPlaylist,
                'data_mode' => $dataMode,
                'runtime_playlist_purpose' => $purpose,
            ],
            'media' => [
                'photo_pages' => $this->mediaStateService->pagesForPlaylist($playlistId, $configuration),
            ],
        ];
    }

    /** @return array{revision: int, pages: array<string, mixed>, generated_at: string|null} */
    public function news(Wallboard $wallboard): array
    {
        $base = $this->playlistResolver->resolveRuntime($wallboard, false);
        if ($base['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO) {
            return $this->demoStateService->news($base['configuration'], $base['playlist_version']);
        }

        $activeAlarm = $this->activeAlarm();
        $resolved = $this->playlistResolver->resolveRuntime(
            $wallboard,
            $this->activeDeploymentExists($activeAlarm),
        );

        return $this->contentSnapshots->news(
            $wallboard,
            $resolved['configuration'],
            $resolved['playlist_id'],
        );
    }

    /** @return array{revision: int, items: list<array<string, mixed>>} */
    public function ticker(Wallboard $wallboard): array
    {
        $base = $this->playlistResolver->resolveRuntime($wallboard, false);
        if ($base['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO) {
            return $this->demoStateService->ticker($base['configuration'], $base['playlist_version']);
        }

        $activeAlarm = $this->activeAlarm();
        $resolved = $this->playlistResolver->resolveRuntime(
            $wallboard,
            $this->activeDeploymentExists($activeAlarm),
        );

        return $this->contentSnapshots->ticker(
            $wallboard,
            $resolved['configuration'],
            $resolved['playlist_id'],
        );
    }

    /** @return array<string, mixed> */
    public function control(Wallboard $wallboard): array
    {
        $base = $this->playlistResolver->resolveRuntime($wallboard, false);
        if ($base['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO) {
            $configuration = $base['configuration'];

            return [
                'generated_at' => ApiDateTime::now(),
                'maintenance' => $this->maintenanceNoticeService->current(),
                'display_profile' => (string) $wallboard->display_profile,
                'data_mode' => WallboardPlaylist::DATA_MODE_DEMO,
                'config_version' => (int) $wallboard->config_version,
                'control_version' => (int) $wallboard->control_version,
                'refresh_version' => (int) $wallboard->refresh_version,
                'runtime_playlist_id' => $base['playlist_id'],
                'runtime_playlist_version' => $base['playlist_version'],
                'active_deployment_playlist' => false,
                'runtime_playlist_purpose' => $base['purpose'],
                'content_versions' => $this->demoStateService->contentVersions(
                    $wallboard,
                    $configuration,
                    $base['playlist_version'],
                ),
                'display' => $this->displayService->display($wallboard, $configuration, false),
                'focus' => null,
                'transient_alert' => null,
                'poll_after_seconds' => 2,
            ];
        }

        $activeAlarm = $this->activeAlarm();
        $resolved = $this->playlistResolver->resolveRuntime(
            $wallboard,
            $this->activeDeploymentExists($activeAlarm),
        );
        $configuration = $resolved['configuration'];
        $focus = $this->focusService->resolve($configuration, $wallboard);

        return [
            'generated_at' => ApiDateTime::now(),
            'maintenance' => $this->maintenanceNoticeService->current(),
            'display_profile' => (string) $wallboard->display_profile,
            'data_mode' => $resolved['data_mode'],
            'config_version' => (int) $wallboard->config_version,
            'control_version' => (int) $wallboard->control_version,
            'refresh_version' => (int) $wallboard->refresh_version,
            'runtime_playlist_id' => $resolved['playlist_id'],
            'runtime_playlist_version' => $resolved['playlist_version'],
            'active_deployment_playlist' => $resolved['active_deployment_playlist'],
            'runtime_playlist_purpose' => $resolved['purpose'],
            'content_versions' => $this->contentSnapshots->contentVersions(
                $wallboard,
                $configuration,
                $resolved['playlist_id'],
            ),
            'display' => $this->displayService->display($wallboard, $configuration, $activeAlarm !== null),
            'focus' => $focus,
            'transient_alert' => $this->transientAlert(),
            'poll_after_seconds' => 2,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function runtime(
        Wallboard $wallboard,
        array $configuration,
        ?array $activeAlarm,
        bool $suppressTakeovers = false,
        bool $wallboardAtlasUrls = true,
    ): array {
        $focus = $suppressTakeovers ? null : $this->focusService->resolve($configuration, $wallboard);
        $transientAlert = $suppressTakeovers ? null : $this->transientAlert();
        $display = $this->displayService->display(
            $wallboard,
            $configuration,
            $suppressTakeovers ? false : $activeAlarm !== null,
        );
        $mapConfiguration = (array) $configuration['map'];
        $pages = collect((array) $configuration['pages']);
        $hasMapPage = $pages->contains(fn (mixed $page): bool => is_array($page)
            && (string) ($page['type'] ?? '') === 'map');
        $hasWeatherRadarPage = $pages->contains(fn (mixed $page): bool => is_array($page)
            && (string) ($page['type'] ?? '') === 'weather_radar');
        $deploymentPages = $pages
            ->filter(fn (mixed $page): bool => is_array($page)
                && in_array((string) ($page['type'] ?? ''), ['deployment_list', 'summary'], true));
        $summaryPages = $deploymentPages
            ->filter(fn (array $page): bool => (string) ($page['type'] ?? '') === 'summary');
        $showsOperationalSummary = $summaryPages->isNotEmpty()
            || ($hasMapPage && ($mapConfiguration['show_summary'] ?? false) === true);
        $pilotMetrics = $showsOperationalSummary
            ? $this->kpiService->pilotMetrics()
            : null;
        $needsDeployments = $showsOperationalSummary
            || ($hasMapPage && (
                ($mapConfiguration['show_active_deployments'] ?? false) === true
                || ($mapConfiguration['show_live_locations'] ?? false) === true
                || ($mapConfiguration['show_deployment_list'] ?? false) === true
            ))
            || $deploymentPages->isNotEmpty();
        $deployments = $needsDeployments
            ? $this->activeDeployments()
            : collect();
        $layers = ($hasMapPage && (($mapConfiguration['show_command_centers'] ?? false) === true
            || ($mapConfiguration['show_historical_deployments'] ?? false) === true)
        )
                ? $this->operationalMap->layers(
                    includePilotHomes: false,
                    includeCommandCenters: (bool) ($mapConfiguration['show_command_centers'] ?? false),
                    includeHistoricalDeployments: (bool) ($mapConfiguration['show_historical_deployments'] ?? false),
                    includeTestDeployments: false,
                )
                : ['command_centers' => [], 'historical_deployments' => [], 'pilot_homes' => []];

        return [
            'generated_at' => ApiDateTime::now(),
            'maintenance' => $suppressTakeovers ? null : $this->maintenanceNoticeService->current(),
            'display' => $display,
            'operational_summary' => [
                'pilot_availability' => $showsOperationalSummary
                    ? [
                        'available' => $pilotMetrics['available'],
                        'total' => $pilotMetrics['total'],
                    ]
                    : ['available' => 0, 'total' => 0],
                'active_alarm' => $activeAlarm,
                'recent_deployments' => $showsOperationalSummary
                    ? $this->recentDeployments()
                    : [],
                'focus' => $focus,
                'transient_alert' => $transientAlert,
            ],
            'kpi' => $this->kpiService->pages($configuration, $pilotMetrics),
            'weather_radar' => $hasWeatherRadarPage
                ? $this->weatherRadar($wallboardAtlasUrls)
                : null,
            'calendar' => $this->calendarService->pages($configuration),
            'map' => [
                'deployments' => $deployments->map(fn (Deployment $deployment): array => $this->deploymentPayload($deployment))->values()->all(),
                'command_centers' => $hasMapPage && ($mapConfiguration['show_command_centers'] ?? false) === true
                    ? $layers['command_centers']
                    : [],
                'historical_deployments' => $hasMapPage && ($mapConfiguration['show_historical_deployments'] ?? false) === true
                    ? $layers['historical_deployments']
                    : [],
                'live_locations' => $hasMapPage && ($mapConfiguration['show_live_locations'] ?? false) === true
                    ? $this->liveLocations($deployments, (bool) ($mapConfiguration['show_routes'] ?? false))
                    : [],
            ],
        ];
    }

    /**
     * @param  array{configuration: array<string, mixed>, playlist_id: string|null, playlist_version: int, active_deployment_playlist: bool, data_mode: string, purpose: string}  $resolved
     * @return array<string, mixed>
     */
    private function demoState(Wallboard $wallboard, array $resolved): array
    {
        $configuration = $resolved['configuration'];
        $runtime = $this->demoStateService->runtime($wallboard, $configuration);
        $static = $this->staticContent(
            $wallboard,
            $configuration,
            $resolved['playlist_id'],
            $resolved['playlist_version'],
            false,
            WallboardPlaylist::DATA_MODE_DEMO,
            $resolved['purpose'],
        );
        $news = $this->demoStateService->news($configuration, $resolved['playlist_version']);
        $ticker = $this->demoStateService->ticker($configuration, $resolved['playlist_version']);

        return [
            'generated_at' => $runtime['generated_at'],
            'maintenance' => $runtime['maintenance'],
            'wallboard' => [
                ...$static['wallboard'],
                'control_version' => (int) $wallboard->control_version,
                'refresh_version' => (int) $wallboard->refresh_version,
                'display' => $runtime['display'],
                'updated_at' => ApiDateTime::dateTime($wallboard->updated_at),
            ],
            'operational_summary' => $runtime['operational_summary'],
            'kpi' => $runtime['kpi'],
            'ticker' => ['items' => $ticker['items']],
            'news' => ['pages' => $news['pages'], 'generated_at' => $news['generated_at']],
            'media' => $static['media'],
            'forecast' => ['pages' => $this->demoStateService->forecast($configuration)],
            'weather_radar' => null,
            'calendar' => $runtime['calendar'],
            'map' => $runtime['map'],
        ];
    }

    /**
     * @return array{precipitation: array<string, mixed>|null, lightning: array<string, mixed>|null}|null
     */
    private function weatherRadar(bool $wallboardAtlasUrls): ?array
    {
        try {
            $metadata = $this->radar->metadata();
        } catch (Throwable) {
            return null;
        }

        return [
            'precipitation' => $this->weatherRadarLayer(
                $metadata['precipitation'] ?? null,
                'precipitation',
                $wallboardAtlasUrls,
            ),
            'lightning' => $this->weatherRadarLayer(
                $metadata['lightning'] ?? null,
                'lightning',
                $wallboardAtlasUrls,
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function weatherRadarLayer(mixed $value, string $kind, bool $wallboardAtlasUrls): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        if (! $wallboardAtlasUrls) {
            return $value;
        }

        $frames = $value['frames'] ?? null;
        if (is_array($frames)) {
            foreach ($frames as $index => $frame) {
                if (! is_array($frame) || ! array_key_exists('image_url', $frame)) {
                    continue;
                }
                $frameUrl = $frame['image_url'];
                if (! is_string($frameUrl)
                    || preg_match(
                        '#\A/api/operational-weather/radar/(precipitation|lightning)/(\d{8}T\d{6}Z-(?:o|f\d{8}T\d{6}Z)-[a-f0-9]{16})\.png\z#D',
                        $frameUrl,
                        $matches,
                    ) !== 1
                    || $matches[1] !== $kind) {
                    $value['frames'][$index]['image_url'] = null;

                    continue;
                }
                $value['frames'][$index]['image_url'] = route('wallboard.weather-radar-atlas', [
                    'kind' => $kind,
                    'snapshot' => $matches[2],
                ], false);
            }
        }

        $atlasUrl = $value['atlas_url'] ?? null;
        if ($atlasUrl === null) {
            return $value;
        }
        if (! is_string($atlasUrl)
            || preg_match(
                '#\A/api/operational-weather/radar/(precipitation|lightning)/(\d{8}T\d{6}Z-(?:o|f\d{8}T\d{6}Z)-[a-f0-9]{16})\.png\z#D',
                $atlasUrl,
                $matches,
            ) !== 1
            || $matches[1] !== $kind) {
            $value['atlas_url'] = null;

            return $value;
        }

        $value['atlas_url'] = route('wallboard.weather-radar-atlas', [
            'kind' => $kind,
            'snapshot' => $matches[2],
        ], false);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function hasWeatherRadarKind(array $configuration, string $kind): bool
    {
        foreach ((array) ($configuration['pages'] ?? []) as $page) {
            if (! is_array($page) || ($page['type'] ?? null) !== 'weather_radar') {
                continue;
            }

            $options = is_array($page['options'] ?? null) ? $page['options'] : [];
            $configuredKind = $options['radar_kind']
                ?? WallboardConfiguration::DEFAULT_WEATHER_RADAR_KIND;
            if ($configuredKind === $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, reference: string, title: string, status: string, priority: string, location_label: string|null, opened_at: string|null}|null
     */
    private function activeAlarm(): ?array
    {
        $deployment = Deployment::query()
            ->whereIn('status', ['dispatching', 'in_progress'])
            ->where('is_test', false)
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->first(['id', 'reference', 'title', 'status', 'priority', 'location_label', 'opened_at']);
        if (! $deployment instanceof Deployment) {
            return null;
        }

        return [
            'id' => (string) $deployment->id,
            'reference' => (string) $deployment->reference,
            'title' => (string) $deployment->title,
            'status' => (string) $deployment->status,
            'priority' => (string) $deployment->priority,
            'location_label' => $deployment->location_label,
            'opened_at' => ApiDateTime::dateTime($deployment->opened_at),
        ];
    }

    /** @param array<string, mixed>|null $activeAlarm */
    private function activeDeploymentExists(?array $activeAlarm): bool
    {
        if (($activeAlarm['status'] ?? null) === 'in_progress') {
            return true;
        }
        if ($activeAlarm === null) {
            return false;
        }

        return Deployment::query()
            ->where('status', 'in_progress')
            ->where('is_test', false)
            ->exists();
    }

    /** @return list<array<string, mixed>> */
    private function recentDeployments(): array
    {
        return Deployment::query()
            ->whereIn('status', ['resolved', 'cancelled'])
            ->where('is_test', false)
            ->orderByRaw('case when closed_at is null then 1 else 0 end')
            ->orderByDesc('closed_at')
            ->orderByDesc('updated_at')
            ->limit(self::RECENT_DEPLOYMENT_LIMIT)
            ->get(['id', 'reference', 'title', 'status', 'priority', 'is_test', 'location_label', 'closed_at'])
            ->map(fn (Deployment $deployment): array => [
                'id' => (string) $deployment->id,
                'reference' => (string) $deployment->reference,
                'title' => (string) $deployment->title,
                'status' => (string) $deployment->status,
                'priority' => (string) $deployment->priority,
                'is_test' => (bool) $deployment->is_test,
                'location_label' => $deployment->location_label,
                'closed_at' => ApiDateTime::dateTime($deployment->closed_at),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function transientAlert(): ?array
    {
        $dispatch = DispatchRequest::query()
            ->with('deployment:id,reference,title,priority,is_test,location_label')
            ->whereIn('status', ['sent', 'escalated'])
            ->whereNotNull('sent_at')
            ->whereHas('deployment')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first(['id', 'deployment_id', 'status', 'priority', 'sent_at']);
        if (! $dispatch instanceof DispatchRequest
            || ! $dispatch->deployment instanceof Deployment
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
        $deployment = $dispatch->deployment;

        return [
            'dispatch_id' => (string) $dispatch->id,
            'deployment_id' => (string) $deployment->id,
            'reference' => (string) $deployment->reference,
            'title' => (string) $deployment->title,
            'priority' => (string) ($deployment->priority ?: $dispatch->priority),
            'location_label' => $deployment->location_label,
            'received_at' => ApiDateTime::dateTime($receivedAt),
            'expires_at' => ApiDateTime::dateTime($expiresAt),
            'is_test' => (bool) $deployment->is_test,
        ];
    }

    /** @return Collection<int, Deployment> */
    private function activeDeployments(): Collection
    {
        return Deployment::query()
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
    private function deploymentPayload(Deployment $deployment): array
    {
        return [
            'id' => (string) $deployment->id,
            'reference' => (string) $deployment->reference,
            'title' => (string) $deployment->title,
            'status' => (string) $deployment->status,
            'priority' => (string) $deployment->priority,
            'is_test' => (bool) $deployment->is_test,
            'location_label' => $deployment->location_label,
            'latitude' => $deployment->latitude === null ? null : (float) $deployment->latitude,
            'longitude' => $deployment->longitude === null ? null : (float) $deployment->longitude,
            'opened_at' => ApiDateTime::dateTime($deployment->opened_at),
        ];
    }

    /**
     * @param  Collection<int, Deployment>  $deployments
     * @return list<array<string, mixed>>
     */
    private function liveLocations(Collection $deployments, bool $includeRoutes): array
    {
        $deploymentIds = $deployments->pluck('id')->map(fn ($id): string => (string) $id)->values();
        if ($deploymentIds->isEmpty()) {
            return [];
        }

        $acceptedPairs = DispatchRecipient::query()
            ->join('dispatch_requests', 'dispatch_requests.id', '=', 'dispatch_recipients.dispatch_request_id')
            ->whereIn('dispatch_requests.deployment_id', $deploymentIds)
            ->whereIn('dispatch_requests.status', ['sent', 'escalated'])
            ->where('dispatch_recipients.response_status', 'accepted')
            ->get([
                'dispatch_requests.deployment_id as deployment_id',
                'dispatch_recipients.user_id as user_id',
            ])
            ->unique(fn ($row): string => (string) $row->deployment_id.'|'.(string) $row->user_id)
            ->values();
        $manualPairs = DeploymentPilotAssignment::query()
            ->whereIn('deployment_id', $deploymentIds)
            ->whereNotNull('user_id')
            ->get(['deployment_id', 'user_id']);
        $acceptedPairs = $acceptedPairs
            ->concat($manualPairs)
            ->unique(fn ($row): string => (string) $row->deployment_id.'|'.(string) $row->user_id)
            ->values();
        if ($acceptedPairs->isEmpty()) {
            return [];
        }

        $userIds = $acceptedPairs->pluck('user_id')->map(fn ($id): string => (string) $id)->unique()->values();
        $latestOperationalStatuses = AvailabilityStatus::query()
            ->latestPerUser()
            ->whereIn('user_id', $userIds)
            ->pluck('status', 'user_id')
            ->mapWithKeys(fn (string $status, string $userId): array => [(string) $userId => $status]);
        $onSceneUserIds = $latestOperationalStatuses
            ->filter(fn (string $status): bool => $status === 'on_scene')
            ->keys()
            ->all();
        $onSceneLookup = array_fill_keys($onSceneUserIds, true);
        $acceptedPairLookup = $acceptedPairs
            ->reject(fn ($row): bool => isset($onSceneLookup[(string) $row->user_id]))
            ->mapWithKeys(fn ($row): array => [
                (string) $row->deployment_id.'|'.(string) $row->user_id => true,
            ]);
        if ($acceptedPairLookup->isEmpty()) {
            return [];
        }

        $consents = LocationSharingConsent::query()
            ->whereIn('deployment_id', $deploymentIds)
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->get(['deployment_id', 'user_id', 'state_version', 'consented_at'])
            ->filter(fn (LocationSharingConsent $consent): bool => $acceptedPairLookup->has(
                (string) $consent->deployment_id.'|'.(string) $consent->user_id,
            ))
            ->keyBy(fn (LocationSharingConsent $consent): string => (string) $consent->deployment_id.'|'.(string) $consent->user_id);
        if ($consents->isEmpty()) {
            return [];
        }

        $latestLocationUpperBound = now()->addMinutes(2);
        $locations = LocationUpdate::query()
            ->whereIn('deployment_id', $deploymentIds)
            ->whereIn('user_id', $userIds)
            ->where('recorded_at', '<=', $latestLocationUpperBound)
            ->where('created_at', '<=', $latestLocationUpperBound)
            ->whereNotExists(function ($newerLocation) use ($latestLocationUpperBound): void {
                $newerLocation
                    ->selectRaw('1')
                    ->from('location_updates as newer_location')
                    ->whereColumn('newer_location.deployment_id', 'location_updates.deployment_id')
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
                'deployment_id',
                'user_id',
                'consent_state_version',
                'latitude',
                'longitude',
                'accuracy_meters',
                'recorded_at',
                'created_at',
            ])
            ->filter(function (LocationUpdate $location) use ($consents): bool {
                $key = (string) $location->deployment_id.'|'.(string) $location->user_id;
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
        $deploymentsById = $deployments->keyBy('id');
        $routes = $includeRoutes
            ? $this->routesForLocations($locations, $deploymentsById)
            : [];

        return $locations
            ->map(function (LocationUpdate $location) use ($latestOperationalStatuses, $users, $routes): array {
                $key = (string) $location->deployment_id.'|'.(string) $location->user_id;
                $route = $routes[$key] ?? null;
                $user = $users->get($location->user_id);
                $latestStatus = $latestOperationalStatuses->get((string) $location->user_id);
                $operationalStatus = in_array($latestStatus, ['en_route', 'on_scene'], true)
                    ? $latestStatus
                    : null;

                return [
                    'deployment_id' => (string) $location->deployment_id,
                    'user_id' => (string) $location->user_id,
                    'user' => $user instanceof User ? ['id' => (string) $user->id, 'name' => (string) $user->name] : null,
                    'dispatch_response_status' => 'accepted',
                    'operational_status' => $operationalStatus,
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
     * @param  Collection<string, Deployment>  $deployments
     * @return array<string, RouteGeometry>
     */
    private function routesForLocations(Collection $locations, Collection $deployments): array
    {
        $routes = [];
        foreach ($locations->groupBy('deployment_id') as $deploymentId => $deploymentLocations) {
            $deployment = $deployments->get($deploymentId);
            if (! $deployment instanceof Deployment) {
                continue;
            }
            $destination = $this->routePoint($deployment->latitude, $deployment->longitude);
            if ($destination === null) {
                continue;
            }

            $origins = [];
            foreach ($deploymentLocations as $location) {
                $origin = $this->routePoint($location->latitude, $location->longitude);
                if ($origin !== null) {
                    $origins[(string) $location->user_id] = $origin;
                }
            }
            foreach ($this->routeGeometryService->routesTo($origins, $destination) as $userId => $route) {
                $routes[(string) $deploymentId.'|'.(string) $userId] = $route;
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
