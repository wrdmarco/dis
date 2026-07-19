<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Services\WallboardForecastClassifier;
use App\Services\WallboardForecastLocationService;
use App\Services\WallboardForecastService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WallboardForecastTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_classifier_is_fail_closed_and_classifies_extended_weather_metrics(): void
    {
        $classifier = app(WallboardForecastClassifier::class);

        $this->assertSame('green', $classifier->classify('wind_speed_kmh', 20)['status']);
        $this->assertSame('orange', $classifier->classify('wind_speed_kmh', 20.1)['status']);
        $this->assertSame('red', $classifier->classify('visibility_m', 1999)['status']);
        $this->assertSame('orange', $classifier->classify('kp_index', 4)['status']);
        $this->assertSame('red', $classifier->classify('kp_index', 6)['status']);
        $this->assertSame('green', $classifier->classify('weather_code', 2)['status']);
        $this->assertSame('orange', $classifier->classify('weather_code', 45)['status']);
        $this->assertSame('red', $classifier->classify('weather_code', 95)['status']);
        $this->assertSame('green', $classifier->classify('temperature_c', 20)['status']);
        $this->assertSame('orange', $classifier->classify('temperature_c', -5)['status']);
        $this->assertSame('red', $classifier->classify('temperature_c', -15)['status']);
        $this->assertSame('orange', $classifier->classify('dew_point_c', 2)['status']);
        $this->assertSame('red', $classifier->classify('precipitation_probability_pct', 80)['status']);
        $this->assertSame('orange', $classifier->classify('cloud_cover_pct', 75)['status']);
        $this->assertSame('unknown', $classifier->classify('wind_speed_kmh', 10, true)['status']);
        $this->assertSame('red', $classifier->overall([
            ['status' => 'green'],
            ['status' => 'unknown'],
            ['status' => 'red'],
        ]));
        $this->assertSame('unknown', $classifier->overall([
            ['status' => 'green'],
            ['status' => 'unknown'],
        ]));
    }

    public function test_address_forecast_resolves_server_side_and_exposes_honest_height_and_display_metadata(): void
    {
        $this->setForecastTestNow();
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://api.open-meteo.com/v1/forecast*' => Http::response($this->weatherPayload(
                latitude: 52.09,
                longitude: 5.12,
                visibility: 9000,
            )),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00', 'kp_index' => 4, 'estimated_kp' => 4.3, 'kp' => '4P'],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $metrics = collect($forecast['metrics'])->keyBy('key');

        $this->assertSame('address', $forecast['location']['mode']);
        $this->assertSame('Utrecht, Nederland', $forecast['location']['label']);
        $this->assertSame(52.0907, $forecast['location']['latitude']);
        $this->assertSame('single_location', $forecast['aggregation']['type']);
        $this->assertTrue($forecast['aggregation']['complete']);
        $this->assertSame(1, $forecast['aggregation']['sample_count']);
        $this->assertSame('Gedeeltelijk bewolkt', $forecast['condition']['label']);
        $this->assertSame($forecast['daylight']['sunrise_earliest'], $forecast['daylight']['sunrise_latest']);
        $this->assertSame('Europe/Amsterdam', $forecast['daylight']['timezone']);
        $this->assertSame(120, $metrics['wind_speed_kmh']['altitude_m']);
        $this->assertSame('120 m boven maaiveld', $metrics['wind_speed_kmh']['source_height_label']);
        $this->assertSame(80, $metrics['wind_speed_kmh']['max_non_red_wind_height_agl_m']);
        $this->assertSame([
            ['height_agl_m' => 10, 'speed_kmh' => 10.0],
            ['height_agl_m' => 80, 'speed_kmh' => 25.0],
            ['height_agl_m' => 120, 'speed_kmh' => 35.0],
        ], $metrics['wind_speed_kmh']['height_samples_agl_m']);
        $this->assertSame(10, $metrics['wind_gust_kmh']['altitude_m']);
        $this->assertSame('9000', $metrics['visibility_m']['display_value']);
        $this->assertSame('m', $metrics['visibility_m']['display_unit']);
        $this->assertSame('NOAA SWPC Kp (1 minuut)', $metrics['kp_index']['source']['name']);
        $this->assertSame('unknown', $metrics['gnss_satellites']['status']);
        $this->assertSame('unknown', $metrics['gnss_satellites_fix']['status']);
        $this->assertStringContainsString('GNSS-ontvanger', $metrics['gnss_satellites']['explanation']);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')
            && str_contains((string) $request['current'], 'wind_speed_10m')
            && str_contains((string) $request['current'], 'wind_speed_80m')
            && str_contains((string) $request['current'], 'wind_speed_120m')
            && $request['timezone'] === 'Europe/Amsterdam');
    }

    public function test_uav_netherlands_averages_exactly_twelve_provinces_in_one_validated_weather_batch(): void
    {
        $this->setForecastTestNow();
        $provinceCoordinates = $this->provinceCoordinates();
        $weatherResponses = [];
        foreach (array_values($provinceCoordinates) as $index => $coordinates) {
            $weatherResponses[] = $this->weatherPayload(
                latitude: $coordinates['latitude'],
                longitude: $coordinates['longitude'],
                temperature: 10 + $index,
                dewPoint: 5 + $index,
                wind10: 10 + $index,
                wind80: 20 + $index,
                wind120: 31 + $index,
                precipitationProbability: 20 + $index,
                cloudCover: 25 + $index,
                visibility: 12000,
                weatherCode: $index < 7 ? 2 : 0,
                sunrise: sprintf('2026-07-20T04:%02d:00Z', $index),
                sunset: sprintf('2026-07-20T20:%02d:00Z', 11 - $index),
            );
        }

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($weatherResponses) {
            if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
                return Http::response($weatherResponses);
            }
            if ($request->url() === 'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json') {
                return Http::response([['time_tag' => '2026-07-20T12:10:00', 'estimated_kp' => 3.2]]);
            }

            return Http::response([], 500);
        });

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->netherlandsPage()],
        ])['forecast-netherlands'];
        $metrics = collect($forecast['metrics'])->keyBy('key');

        $this->assertSame('UAV Nederland', $forecast['location']['label']);
        $this->assertSame('province_average', $forecast['aggregation']['type']);
        $this->assertSame(12, $forecast['aggregation']['sample_count']);
        $this->assertSame(12, $forecast['aggregation']['expected_sample_count']);
        $this->assertTrue($forecast['aggregation']['complete']);
        $this->assertTrue($forecast['aggregation']['fresh']);
        $this->assertSame(15.5, $metrics['temperature_c']['value']);
        $this->assertSame(36.5, $metrics['wind_speed_kmh']['value']);
        $this->assertSame(25.5, $metrics['wind_speed_kmh']['height_samples_agl_m'][1]['speed_kmh']);
        $this->assertSame(80, $metrics['wind_speed_kmh']['max_non_red_wind_height_agl_m']);
        $this->assertSame('12.00', $metrics['visibility_m']['display_value']);
        $this->assertSame('km', $metrics['visibility_m']['display_unit']);
        $this->assertSame(2.0, $forecast['condition']['code']);
        $this->assertSame('2026-07-20T06:00:00+02:00', $forecast['daylight']['sunrise_earliest']);
        $this->assertSame('2026-07-20T06:11:00+02:00', $forecast['daylight']['sunrise_latest']);
        $this->assertSame('Open-Meteo (12 provincies)', $metrics['wind_speed_kmh']['source']['name']);
        $this->assertStringContainsString('exact alle 12', $forecast['scope_note']);

        $this->assertSame(0, $this->sentCountStartingWith('https://nominatim.openstreetmap.org/search'));
        $this->assertSame(1, $this->sentCountStartingWith('https://api.open-meteo.com/v1/forecast'));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')
            && substr_count((string) $request['latitude'], ',') === 11
            && substr_count((string) $request['longitude'], ',') === 11);
    }

    public function test_national_forecast_fails_closed_when_managed_province_set_is_incomplete(): void
    {
        $this->setForecastTestNow();
        config()->set(
            'dis.wallboards.uav_forecast.province_reference_points',
            array_slice((array) config('dis.wallboards.uav_forecast.province_reference_points'), 0, 11),
        );
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json') {
                return Http::response([['time_tag' => '2026-07-20T12:10:00', 'estimated_kp' => 2.0]]);
            }

            return Http::response([], 500);
        });

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->netherlandsPage()],
        ])['forecast-netherlands'];

        $this->assertFalse($forecast['aggregation']['complete']);
        $this->assertSame(0, $forecast['aggregation']['sample_count']);
        $this->assertSame(12, $forecast['aggregation']['expected_sample_count']);
        $this->assertNull($forecast['location']['latitude']);
        $this->assertSame('unknown', $forecast['overall_status']);
        $this->assertSame(0, $this->sentCountStartingWith('https://api.open-meteo.com/v1/forecast'));
    }

    public function test_unresolvable_address_is_rejected_before_persistence(): void
    {
        $this->setForecastTestNow();
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([]),
        ]);

        try {
            app(WallboardForecastLocationService::class)->assertResolvableAddresses([
                'pages' => [$this->addressPage()],
            ]);
            $this->fail('Een onvindbaar adres had niet voor opslag geaccepteerd mogen worden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'configuration.pages.0.options.location_label',
                $exception->errors(),
            );
        }
    }

    public function test_kp_uses_current_feed_then_fallback_and_reuses_exact_fifteen_minute_cache(): void
    {
        $this->setForecastTestNow();
        $calls = ['weather' => 0, 'kp_current' => 0, 'kp_fallback' => 0];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$calls) {
            if (str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search')) {
                return Http::response([['lat' => '52.0907', 'lon' => '5.1214']]);
            }
            if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
                $calls['weather']++;

                return Http::response($this->weatherPayload(latitude: 52.09, longitude: 5.12));
            }
            if ($request->url() === 'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json') {
                $calls['kp_current']++;

                return Http::response([['time_tag' => '2026-07-20T05:00:00', 'estimated_kp' => 2.0]]);
            }
            if ($request->url() === 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json') {
                $calls['kp_fallback']++;

                return Http::response([['time_tag' => '2026-07-20T12:00:00', 'Kp' => 4.7]]);
            }

            return Http::response([], 500);
        });

        $service = app(WallboardForecastService::class);
        $first = $service->pages(['pages' => [$this->addressPage()]])['forecast-utrecht'];
        $kp = collect($first['metrics'])->firstWhere('key', 'kp_index');
        $this->assertSame(4.7, $kp['value']);
        $this->assertSame('NOAA SWPC Kp (3 uur)', $kp['source']['name']);
        $this->assertSame(['weather' => 1, 'kp_current' => 1, 'kp_fallback' => 1], $calls);

        CarbonImmutable::setTestNow('2026-07-20T12:29:59Z');
        $service->pages(['pages' => [$this->addressPage()]]);
        $this->assertSame(['weather' => 1, 'kp_current' => 1, 'kp_fallback' => 1], $calls);

        CarbonImmutable::setTestNow('2026-07-20T12:30:01Z');
        $service->pages(['pages' => [$this->addressPage()]]);
        $this->assertSame(['weather' => 2, 'kp_current' => 2, 'kp_fallback' => 2], $calls);
    }

    public function test_failed_kp_refresh_uses_last_good_only_as_stale_unknown(): void
    {
        $this->setForecastTestNow();
        $failKp = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$failKp) {
            if (str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search')) {
                return Http::response([['lat' => '52.0907', 'lon' => '5.1214']]);
            }
            if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
                return Http::response($this->weatherPayload(latitude: 52.09, longitude: 5.12));
            }
            if (str_contains($request->url(), 'services.swpc.noaa.gov')) {
                return $failKp
                    ? Http::response([], 503)
                    : Http::response([['time_tag' => '2026-07-20T12:10:00', 'estimated_kp' => 3.0]]);
            }

            return Http::response([], 500);
        });

        $service = app(WallboardForecastService::class);
        $service->pages(['pages' => [$this->addressPage()]]);
        $failKp = true;
        CarbonImmutable::setTestNow('2026-07-20T12:30:01Z');
        $forecast = $service->pages(['pages' => [$this->addressPage()]])['forecast-utrecht'];
        $kp = collect($forecast['metrics'])->firstWhere('key', 'kp_index');

        $this->assertSame(3.0, $kp['value']);
        $this->assertTrue($kp['stale']);
        $this->assertSame('unknown', $kp['status']);
    }

    public function test_configuration_defaults_to_netherlands_migrates_legacy_coordinates_and_validates_visible_blocks(): void
    {
        $default = $this->netherlandsPage();
        $default['options'] = [];
        $normalized = WallboardConfiguration::normalize(['pages' => [$default]]);
        $this->assertSame([
            'location_mode' => 'netherlands',
            'visible_blocks' => WallboardConfiguration::FORECAST_VISIBLE_BLOCKS,
        ], $normalized['pages'][0]['options']);

        $legacy = $this->addressPage();
        $legacy['options'] = [
            'location_label' => 'Utrecht, Nederland',
            'latitude' => 52.0907,
            'longitude' => 5.1214,
        ];
        $normalized = WallboardConfiguration::normalize(['pages' => [$legacy]]);
        $this->assertSame('address', $normalized['pages'][0]['options']['location_mode']);
        $this->assertArrayNotHasKey('latitude', $normalized['pages'][0]['options']);
        $this->assertArrayNotHasKey('longitude', $normalized['pages'][0]['options']);

        $hidden = $this->netherlandsPage();
        $hidden['options']['visible_blocks'] = [];
        $normalized = WallboardConfiguration::normalize(['pages' => [$hidden]]);
        $this->assertSame([], $normalized['pages'][0]['options']['visible_blocks']);

        $invalid = $this->addressPage();
        $invalid['options']['latitude'] = 52.0;
        $invalid['options']['longitude'] = 5.0;
        $this->assertConfigurationError($invalid, 'configuration.pages.0.options');

        $duplicate = $this->netherlandsPage();
        $duplicate['options']['visible_blocks'] = ['weather', 'weather'];
        $this->assertConfigurationError($duplicate, 'configuration.pages.0.options.visible_blocks');

        $unknown = $this->netherlandsPage();
        $unknown['options']['visible_blocks'] = ['weather', 'provider_url'];
        $this->assertConfigurationError($unknown, 'configuration.pages.0.options.visible_blocks.1');
    }

    public function test_shared_playlist_requests_accept_the_complete_forecast_contract(): void
    {
        foreach ([
            [new StoreWallboardPlaylistRequest, ['name' => 'UAV playlist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ] as [$request, $base]) {
            $request->initialize([
                ...$base,
                'configuration' => ['pages' => [$this->netherlandsPage()]],
            ]);
            $validator = Validator::make($request->all(), $request->rules());
            foreach ($request->after() as $callback) {
                $validator->after($callback);
            }

            $validated = $validator->validate();
            $this->assertSame('netherlands', $validated['configuration']['pages'][0]['options']['location_mode']);
            $this->assertSame(
                WallboardConfiguration::FORECAST_VISIBLE_BLOCKS,
                $validated['configuration']['pages'][0]['options']['visible_blocks'],
            );
        }
    }

    /** @return array<string, mixed> */
    private function addressPage(): array
    {
        return [
            'id' => 'forecast-utrecht',
            'name' => 'UAV Forecast Utrecht',
            'type' => 'uav_forecast',
            'duration_seconds' => 30,
            'options' => [
                'location_mode' => 'address',
                'location_label' => 'Utrecht, Nederland',
                'visible_blocks' => WallboardConfiguration::FORECAST_VISIBLE_BLOCKS,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function netherlandsPage(): array
    {
        return [
            'id' => 'forecast-netherlands',
            'name' => 'UAV Nederland',
            'type' => 'uav_forecast',
            'duration_seconds' => 30,
            'options' => [
                'location_mode' => 'netherlands',
                'visible_blocks' => WallboardConfiguration::FORECAST_VISIBLE_BLOCKS,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weatherPayload(
        float $latitude,
        float $longitude,
        float $temperature = 18,
        float $dewPoint = 12,
        float $wind10 = 10,
        float $wind80 = 25,
        float $wind120 = 35,
        float $windGust = 30,
        float $windDirection = 90,
        float $precipitationProbability = 20,
        float $precipitation = 0,
        float $cloudCover = 40,
        float $visibility = 10000,
        int $weatherCode = 2,
        string $sunrise = '2026-07-20T04:30:00Z',
        string $sunset = '2026-07-20T20:45:00Z',
    ): array {
        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current' => [
                'time' => '2026-07-20T12:10:00Z',
                'temperature_2m' => $temperature,
                'dew_point_2m' => $dewPoint,
                'wind_speed_10m' => $wind10,
                'wind_speed_80m' => $wind80,
                'wind_speed_120m' => $wind120,
                'wind_gusts_10m' => $windGust,
                'wind_direction_120m' => $windDirection,
                'precipitation_probability' => $precipitationProbability,
                'precipitation' => $precipitation,
                'cloud_cover' => $cloudCover,
                'visibility' => $visibility,
                'weather_code' => $weatherCode,
            ],
            'daily' => [
                'sunrise' => [$sunrise],
                'sunset' => [$sunset],
            ],
        ];
    }

    /** @return array<string, array{latitude: float, longitude: float}> */
    private function provinceCoordinates(): array
    {
        return collect((array) config('dis.wallboards.uav_forecast.province_reference_points'))
            ->mapWithKeys(fn (array $reference): array => [
                $reference['label'] => [
                    'latitude' => (float) $reference['latitude'],
                    'longitude' => (float) $reference['longitude'],
                ],
            ])
            ->all();
    }

    private function setForecastTestNow(): void
    {
        CarbonImmutable::setTestNow('2026-07-20T12:15:00Z');
        Cache::flush();
        config([
            'dis.geocoding.enabled' => true,
            'dis.geocoding.provider' => 'nominatim',
            'dis.geocoding.nominatim_url' => 'https://nominatim.openstreetmap.org/search',
            'dis.wallboards.uav_forecast.cache_seconds' => 900,
        ]);
    }

    private function sentCountStartingWith(string $url): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_starts_with($pair[0]->url(), $url))
            ->count();
    }

    /** @param array<string, mixed> $page */
    private function assertConfigurationError(array $page, string $field): void
    {
        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail("{$field} had niet mogen normaliseren.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
