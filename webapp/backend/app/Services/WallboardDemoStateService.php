<?php

namespace App\Services;

use App\Models\Wallboard;
use App\Support\ApiDateTime;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;

final class WallboardDemoStateService
{
    public const FIXTURE_VERSION = 1;

    public function __construct(
        private readonly WallboardDisplayService $displayService,
        private readonly WallboardKpiService $kpiService,
        private readonly WallboardMaintenanceNoticeService $maintenanceNoticeService,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function runtime(
        Wallboard $wallboard,
        array $configuration,
        bool $includeMaintenance = true,
    ): array {
        $now = CarbonImmutable::now((string) config('app.timezone', 'Europe/Amsterdam'));
        $pages = collect((array) ($configuration['pages'] ?? []));
        $hasMap = $pages->contains($this->pageType('map'));
        $hasIncidentPage = $pages->contains(
            static fn (mixed $page): bool => is_array($page)
                && in_array($page['type'] ?? null, ['incident_list', 'summary'], true),
        );
        $showsSummary = $pages->contains($this->pageType('summary'))
            || ($hasMap && (($configuration['map']['show_summary'] ?? false) === true));

        return [
            'generated_at' => ApiDateTime::dateTime($now),
            'maintenance' => $includeMaintenance ? $this->maintenanceNoticeService->current() : null,
            'display' => $this->displayService->display($wallboard, $configuration, false),
            'operational_summary' => [
                'pilot_availability' => $showsSummary
                    ? ['available' => 12, 'total' => 18]
                    : ['available' => 0, 'total' => 0],
                'active_alarm' => null,
                'recent_incidents' => $showsSummary ? $this->recentIncidents($now) : [],
                'focus' => null,
                'transient_alert' => null,
            ],
            'kpi' => $this->kpiService->demoPages($configuration),
            'calendar' => $this->calendar($configuration, $now),
            'map' => $this->map(
                (array) ($configuration['map'] ?? []),
                $hasMap,
                $hasIncidentPage,
                $now,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{revision: int, pages: array<string, mixed>, generated_at: string}
     */
    public function news(array $configuration, int $playlistVersion): array
    {
        $anchor = $this->anchor();
        $templates = [
            [
                'id' => 'demo-news-veilig-vliegen',
                'title' => 'Demo: veilig vliegen begint met een goede briefing',
                'excerpt' => 'Dit is fictieve voorbeeldinhoud om de nieuwsweergave te beoordelen.',
                'url' => 'https://example.invalid/dis-demo/veilig-vliegen',
                'published_at' => ApiDateTime::dateTime($anchor->subHour()),
            ],
            [
                'id' => 'demo-news-oefening',
                'title' => 'Demo: gezamenlijk oefenen met het droneteam',
                'excerpt' => 'Geen echt nieuwsbericht en niet afkomstig uit een externe feed.',
                'url' => 'https://example.invalid/dis-demo/oefening',
                'published_at' => ApiDateTime::dateTime($anchor->subDay()->addHours(5)),
            ],
            [
                'id' => 'demo-news-techniek',
                'title' => 'Demo: nieuwe techniek in de praktijk getest',
                'excerpt' => 'Vaste demodata zonder operationele gegevens of persoonsgegevens.',
                'url' => 'https://example.invalid/dis-demo/techniek',
                'published_at' => ApiDateTime::dateTime($anchor->subDays(2)->addHour()),
            ],
        ];
        $pages = [];
        foreach ((array) ($configuration['pages'] ?? []) as $page) {
            if (! is_array($page) || ($page['type'] ?? null) !== 'news') {
                continue;
            }
            $limit = max(1, min(12, (int) ($page['options']['max_items'] ?? 6)));
            $pages[(string) $page['id']] = [
                'items' => array_map(static fn (array $item): array => [
                    ...$item,
                    'source' => 'custom',
                    'source_id' => 'dis-demo',
                    'source_label' => 'DIS DEMO',
                    'image_url' => null,
                ], array_slice($templates, 0, $limit)),
                'fallback_used' => false,
                'lookback_days' => 7,
            ];
        }

        return [
            'revision' => $this->revision($playlistVersion),
            'pages' => $pages,
            'generated_at' => ApiDateTime::dateTime($anchor),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{revision: int, items: list<array<string, string>>}
     */
    public function ticker(array $configuration, int $playlistVersion): array
    {
        $enabled = ($configuration['ticker']['enabled'] ?? false) === true;

        return [
            'revision' => $this->revision($playlistVersion),
            'items' => $enabled ? [[
                'source_id' => 'dis-demo',
                'source_type' => 'internal',
                'source_label' => 'DIS DEMO',
                'text' => 'Fictieve demodata · geen live operationele informatie',
            ]] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array<string, mixed>>
     */
    public function forecast(array $configuration): array
    {
        $anchor = $this->anchor();
        $generatedAt = ApiDateTime::dateTime($anchor);
        $source = ['name' => 'DIS demo (fictief)', 'url' => null];
        $metrics = [
            $this->forecastMetric('weather_code', 'Weerbeeld', 2, null, 'green', $source, $generatedAt, 'Licht bewolkt'),
            $this->forecastMetric('temperature_c', 'Temperatuur', 18.4, '°C', 'green', $source, $generatedAt, '18,4'),
            $this->forecastMetric('dew_point_c', 'Dauwpunt', 11.2, '°C', 'green', $source, $generatedAt, '11,2'),
            $this->forecastMetric('wind_speed_kmh', 'Wind op 120 m AGL', 22.5, 'km/u', 'orange', $source, $generatedAt, '22,5', 120),
            $this->forecastMetric('wind_gust_kmh', 'Windstoten op 10 m AGL', 31.0, 'km/u', 'orange', $source, $generatedAt, '31,0', 10),
            $this->forecastMetric('wind_direction_degrees', 'Windrichting op 120 m AGL', 245, '°', 'green', $source, $generatedAt, '245', 120),
            $this->forecastMetric('precipitation_probability_pct', 'Neerslagkans', 15, '%', 'green', $source, $generatedAt, '15'),
            $this->forecastMetric('precipitation_mm', 'Neerslag', 0.0, 'mm', 'green', $source, $generatedAt, '0,0'),
            $this->forecastMetric('cloud_cover_pct', 'Totale modelbewolking', 64, '%', 'orange', $source, $generatedAt, '64'),
            $this->forecastMetric('low_cloud_cover_pct', 'Lage bewolking', 28, '%', 'green', $source, $generatedAt, '28'),
            $this->forecastMetric('visibility_m', 'Zicht', 18000, 'm', 'green', $source, $generatedAt, '18,00', null, 'km'),
            $this->forecastMetric('kp_index', 'Geomagnetische activiteit', 2.3, 'Kp', 'green', $source, $generatedAt, '2,30'),
            $this->forecastMetric('gnss_satellites', 'Zichtbare GNSS-satellieten', null, null, 'unknown', $source, $generatedAt, null),
            $this->forecastMetric('gnss_satellites_fix', 'GNSS-satellieten in fix', null, null, 'unknown', $source, $generatedAt, null),
        ];
        $metrics[3]['height_samples_agl_m'] = [
            ['height_agl_m' => 10, 'speed_kmh' => 14.2],
            ['height_agl_m' => 80, 'speed_kmh' => 20.8],
            ['height_agl_m' => 120, 'speed_kmh' => 22.5],
        ];
        $metrics[3]['max_non_red_wind_height_agl_m'] = 120;
        $metrics[9]['cloud_layers'] = [
            'low_pct' => 28,
            'mid_pct' => 42,
            'high_pct' => 31,
            'total_pct' => 64,
        ];
        $metrics[9]['cloud_base_observation'] = [
            'status' => 'measured',
            'base_height_m' => 820,
            'height_reference' => 'mean_sea_level',
            'layers' => [
                ['height_m' => 820, 'cover_okta' => 3],
                ['height_m' => 1450, 'cover_okta' => 5],
            ],
            'station' => [
                'id' => 'DEMO',
                'name' => 'Demo-meetstation (fictief)',
                'distance_km' => 4.2,
            ],
            'observed_at' => $generatedAt,
            'period_minutes' => 30,
            'attribution' => 'DIS_DEMO',
        ];

        $pages = [];
        foreach ((array) ($configuration['pages'] ?? []) as $page) {
            if (! is_array($page) || ($page['type'] ?? null) !== 'uav_forecast') {
                continue;
            }
            $options = (array) ($page['options'] ?? []);
            $mode = ($options['location_mode'] ?? null) === 'address' ? 'address' : 'netherlands';
            $expected = $mode === 'netherlands' ? 12 : 1;
            $pages[(string) $page['id']] = [
                'location' => [
                    'mode' => $mode,
                    'label' => $mode === 'netherlands' ? 'Demo Nederland' : 'Demolocatie (fictief)',
                    'latitude' => null,
                    'longitude' => null,
                ],
                'aggregation' => [
                    'type' => $mode === 'netherlands' ? 'province_average' : 'single_location',
                    'sample_count' => $expected,
                    'expected_sample_count' => $expected,
                    'complete' => true,
                    'fresh' => true,
                ],
                'visible_blocks' => array_values((array) ($options['visible_blocks'] ?? WallboardConfiguration::FORECAST_VISIBLE_BLOCKS)),
                'overall_status' => 'orange',
                'generated_at' => $generatedAt,
                'condition' => [
                    'code' => 2,
                    'label' => 'Licht bewolkt · DEMO',
                    'status' => 'green',
                    'stale' => false,
                    'source' => $source,
                    'measured_at' => $generatedAt,
                ],
                'daylight' => [
                    'timezone' => 'Europe/Amsterdam',
                    'sunrise_earliest' => ApiDateTime::dateTime($anchor->startOfDay()->addHours(6)->addMinutes(5)),
                    'sunrise_latest' => ApiDateTime::dateTime($anchor->startOfDay()->addHours(6)->addMinutes(15)),
                    'sunset_earliest' => ApiDateTime::dateTime($anchor->startOfDay()->addHours(21)->addMinutes(20)),
                    'sunset_latest' => ApiDateTime::dateTime($anchor->startOfDay()->addHours(21)->addMinutes(30)),
                    'stale' => false,
                    'source' => $source,
                ],
                'wind_profile' => [
                    'samples' => $metrics[3]['height_samples_agl_m'],
                    'max_non_red_wind_height_agl_m' => 120,
                    'stale' => false,
                ],
                'metrics' => $metrics,
                'scope_note' => 'Vaste fictieve waarden om de UAV Forecast-weergave te demonstreren.',
                'disclaimer' => 'DEMO: deze fictieve waarden mogen nooit voor een vliegbeslissing worden gebruikt.',
            ];
        }

        return $pages;
    }

    /** @param array<string, mixed> $configuration
     * @return array{static: string, news: string, ticker: string}
     */
    public function contentVersions(Wallboard $wallboard, array $configuration, int $playlistVersion): array
    {
        $signature = 'demo-v'.self::FIXTURE_VERSION
            .':d'.$this->anchor()->format('Ymd')
            .':p'.$this->revision($playlistVersion);

        return [
            'static' => 's:'.(int) $wallboard->config_version.':'.$signature,
            'news' => $signature.':news:'.$this->configurationHash($configuration, 'news'),
            'ticker' => $signature.':ticker:'.$this->configurationHash($configuration, 'ticker'),
        ];
    }

    /** @return \Closure(mixed): bool */
    private function pageType(string $type): \Closure
    {
        return static fn (mixed $page): bool => is_array($page) && ($page['type'] ?? null) === $type;
    }

    /** @return list<array<string, mixed>> */
    private function recentIncidents(CarbonImmutable $now): array
    {
        return [
            $this->recentIncident('demo-recent-1', 'DEMO-2026-0042', 'Demo: zoekactie in natuurgebied', 'resolved', 'high', $now->subHours(2)),
            $this->recentIncident('demo-recent-2', 'DEMO-2026-0041', 'Demo: ondersteuning brandweer', 'cancelled', 'normal', $now->subHours(5)),
            $this->recentIncident('demo-recent-3', 'DEMO-2026-0040', 'Demo: inspectie na stormschade', 'resolved', 'normal', $now->subDay()),
        ];
    }

    /** @return array<string, mixed> */
    private function recentIncident(
        string $id,
        string $reference,
        string $title,
        string $status,
        string $priority,
        CarbonImmutable $closedAt,
    ): array {
        return [
            'id' => $id,
            'reference' => $reference,
            'title' => $title,
            'status' => $status,
            'priority' => $priority,
            'is_test' => false,
            'location_label' => 'Demolocatie · fictief',
            'closed_at' => ApiDateTime::dateTime($closedAt),
        ];
    }

    /** @param array<string, mixed> $configuration
     * @return array{generated_at: string|null, pages: array<string, array{items: list<array<string, mixed>>}>}
     */
    private function calendar(array $configuration, CarbonImmutable $now): array
    {
        $templates = [
            $this->calendarItem('demo-calendar-1', 'Demo: operationele briefing', 'meeting', $now->addHour(), $now->addHours(2)),
            $this->calendarItem('demo-calendar-2', 'Demo: vliegtraining', 'training', $now->addDay(), $now->addDay()->addHours(3)),
            $this->calendarItem('demo-calendar-3', 'Demo: multidisciplinaire oefening', 'exercise', $now->addDays(3), null),
        ];
        $pages = [];
        foreach ((array) ($configuration['pages'] ?? []) as $page) {
            if (! is_array($page) || ($page['type'] ?? null) !== 'calendar') {
                continue;
            }
            $limit = max(1, min(12, (int) ($page['options']['max_items'] ?? 6)));
            $pages[(string) $page['id']] = ['items' => array_slice($templates, 0, $limit)];
        }

        return ['generated_at' => ApiDateTime::dateTime($now), 'pages' => $pages];
    }

    /** @return array<string, mixed> */
    private function calendarItem(
        string $id,
        string $title,
        string $type,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'type' => $type,
            'starts_at' => ApiDateTime::dateTime($startsAt),
            'ends_at' => ApiDateTime::dateTime($endsAt),
            'location_label' => 'Demolocatie · fictief',
            'description' => 'Fictief kalenderitem voor presentatiedoeleinden.',
            'team' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $mapConfiguration
     * @return array<string, list<array<string, mixed>>>
     */
    private function map(
        array $mapConfiguration,
        bool $hasMap,
        bool $hasIncidentPage,
        CarbonImmutable $now,
    ): array {
        $needsIncidents = $hasIncidentPage || ($hasMap && (
            ($mapConfiguration['show_active_incidents'] ?? false) === true
            || ($mapConfiguration['show_live_locations'] ?? false) === true
        ));
        $incidents = $needsIncidents ? [[
            'id' => 'demo-incident-1',
            'reference' => 'DEMO-2026-0043',
            'title' => 'Demo: vermiste wandelaar',
            'status' => 'in_progress',
            'priority' => 'high',
            'is_test' => false,
            'location_label' => 'Demogebied · fictief',
            'latitude' => 52.09,
            'longitude' => 5.12,
            'opened_at' => ApiDateTime::dateTime($now->subMinutes(42)),
        ], [
            'id' => 'demo-incident-2',
            'reference' => 'DEMO-2026-0044',
            'title' => 'Demo: inspectie infrastructuur',
            'status' => 'active',
            'priority' => 'normal',
            'is_test' => false,
            'location_label' => 'Demozone · fictief',
            'latitude' => 52.16,
            'longitude' => 5.38,
            'opened_at' => ApiDateTime::dateTime($now->subMinutes(18)),
        ]] : [];

        return [
            'incidents' => $incidents,
            'command_centers' => $hasMap && ($mapConfiguration['show_command_centers'] ?? false) === true ? [[
                'id' => 'demo-command-center-1',
                'name' => 'Demo commandocentrum',
                'address' => 'Fictief adres',
                'latitude' => 52.05,
                'longitude' => 5.18,
            ]] : [],
            'historical_incidents' => $hasMap && ($mapConfiguration['show_historical_incidents'] ?? false) === true ? [[
                'id' => 'demo-history-1',
                'reference' => 'DEMO-2026-0039',
                'title' => 'Demo: afgeronde inzet',
                'status' => 'resolved',
                'priority' => 'normal',
                'location_label' => 'Demolocatie · fictief',
                'latitude' => 51.98,
                'longitude' => 5.24,
                'closed_at' => ApiDateTime::dateTime($now->subDays(2)),
            ]] : [],
            'live_locations' => $hasMap
                && ($mapConfiguration['show_live_locations'] ?? false) === true
                && $incidents !== [] ? [[
                    'incident_id' => 'demo-incident-1',
                    'user_id' => 'demo-pilot-alpha',
                    'user' => ['id' => 'demo-pilot-alpha', 'name' => 'Demopiloot Alfa'],
                    'dispatch_response_status' => 'accepted',
                    'operational_status' => 'en_route',
                    'sharing_status' => 'shared',
                    'location_is_current' => true,
                    'latitude' => 52.075,
                    'longitude' => 5.105,
                    'accuracy_meters' => 8.0,
                    'recorded_at' => ApiDateTime::dateTime($now),
                    'eta_minutes' => 7,
                    'eta_source' => 'navigation',
                    'route' => ($mapConfiguration['show_routes'] ?? false) === true ? [
                        'source' => 'navigation',
                        'duration_seconds' => 420,
                        'distance_meters' => 5400,
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => [[5.105, 52.075], [5.112, 52.082], [5.12, 52.09]],
                        ],
                    ] : null,
                ]] : [],
        ];
    }

    /**
     * @param  array{name: string, url: null}  $source
     * @return array<string, mixed>
     */
    private function forecastMetric(
        string $key,
        string $label,
        int|float|null $value,
        ?string $unit,
        string $status,
        array $source,
        ?string $measuredAt,
        ?string $displayValue,
        ?int $altitude = null,
        ?string $displayUnit = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'display_value' => $displayValue,
            'display_unit' => $displayUnit,
            'status' => $status,
            'stale' => false,
            'source' => $source,
            'measured_at' => $measuredAt,
            'explanation' => 'Fictieve voorbeeldwaarde voor de demoplaylist.',
            'altitude_m' => $altitude,
            'source_height_label' => $altitude === null ? null : $altitude.' m boven maaiveld (demo)',
            'height_samples_agl_m' => [],
            'max_non_red_wind_height_agl_m' => null,
            'cloud_layers' => null,
            'cloud_base_observation' => null,
        ];
    }

    private function revision(int $playlistVersion): int
    {
        return max(1, $playlistVersion);
    }

    private function anchor(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone', 'Europe/Amsterdam'))
            ->startOfDay()
            ->addHours(10);
    }

    /** @param array<string, mixed> $configuration */
    private function configurationHash(array $configuration, string $kind): string
    {
        $relevant = $kind === 'news'
            ? array_values(array_filter(
                (array) ($configuration['pages'] ?? []),
                $this->pageType('news'),
            ))
            : (array) ($configuration['ticker'] ?? []);

        return hash('sha256', json_encode($relevant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
