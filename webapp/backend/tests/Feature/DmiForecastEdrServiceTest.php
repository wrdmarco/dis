<?php

namespace Tests\Feature;

use App\Services\DmiForecastEdrService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DmiForecastEdrServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-20T12:15:00Z');
        Cache::flush();
        config([
            'dis.wallboards.uav_forecast.cache_seconds' => 900,
            'dis.wallboards.uav_forecast.last_good_cache_seconds' => 21600,
            'dis.wallboards.uav_forecast.dmi_model_cache_seconds' => 600,
            'dis.wallboards.uav_forecast.dmi_model_stale_seconds' => 21600,
            'dis.wallboards.uav_forecast.dmi_valid_window_seconds' => 5400,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_reads_dmi_edr_on_demand_with_native_uav_heights_and_no_fabricated_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/collections/harmonie_dini_sf/instances')) {
                return Http::response($this->instancesPayload());
            }
            if (str_contains($request->url(), '/instances/2026-07-20T090000Z/position')) {
                return Http::response($this->positionPayload(5.1214, 52.0907));
            }

            return Http::response([], 500);
        });

        $service = app(DmiForecastEdrService::class);
        $reading = $service->forResolution($this->resolution());

        $this->assertTrue($reading['complete']);
        $this->assertFalse($reading['stale']);
        $this->assertSame(1, $reading['sample_count']);
        $this->assertSame('DMI HARMONIE DINI', $reading['source']['name']);
        $this->assertSame('CC BY 4.0', $reading['source']['license']);
        $this->assertSame(
            'https://www.dmi.dk/friedata/dokumentation/terms-of-use',
            $reading['source']['license_url'],
        );
        $this->assertSame('Contains modified DMI data', $reading['source']['attribution']);
        $this->assertTrue($reading['source']['modified']);
        $this->assertSame('DIS', $reading['source']['processed_by']);
        $this->assertEqualsWithDelta(6.85, $reading['temperature_c'], 0.001);
        $this->assertEqualsWithDelta(18.0, $reading['wind_speed_10m_kmh'], 0.001);
        $this->assertEqualsWithDelta(28.8, $reading['wind_speed_100m_kmh'], 0.001);
        $this->assertEqualsWithDelta(36.0, $reading['wind_speed_150m_kmh'], 0.001);
        $this->assertSame($reading['wind_speed_100m_kmh'], $reading['wind_speed_kmh']);
        // valid_at is 12:00: "+1 uur" is 12:00 to 13:00 and +3h is
        // exactly the three intervals ending at 15:00 (not 11:00 to 15:00).
        $this->assertEqualsWithDelta(0.1, $reading['precipitation_mm'], 0.001);
        $this->assertEqualsWithDelta(0.5, $reading['forecast_precipitation_peak_mm_h'], 0.01);
        $this->assertSame('2026-07-20T12:00:00+00:00', $reading['forecast_precipitation_first_at']);
        $this->assertSame('2026-07-20T15:00:00+00:00', $reading['forecast_precipitation_until']);
        $this->assertEqualsWithDelta(40.0, $reading['cloud_cover_pct'], 0.001);
        $this->assertSame(850.0, $reading['cloud_base_m']);
        $this->assertSame(1, $reading['cloud_base_sample_count']);
        $this->assertSame(1, $reading['cloud_base_expected_sample_count']);
        $this->assertTrue($reading['cloud_base_complete']);
        $this->assertNull($reading['weather_code']);
        $this->assertNull($reading['precipitation_probability_pct']);
        $this->assertTrue($reading['thunderstorm_expected']);
        $this->assertEqualsWithDelta(15.0, $reading['thunderstorm_probability_pct'], 0.001);
        $this->assertSame('2026-07-20T13:00:00+00:00', $reading['thunderstorm_first_expected_at']);
        $this->assertSame('2026-07-20T09:00:00+00:00', $reading['model_run_at']);
        $this->assertSame('2026-07-20T12:00:00+00:00', $reading['valid_at']);
        $this->assertIsString($reading['sunrise_earliest']);
        $this->assertIsString($reading['sunset_latest']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/position')
            && $request['crs'] === 'crs84'
            && $request['f'] === 'GeoJSON'
            && $request['datetime'] === '2026-07-20T11:00:00Z/2026-07-20T16:00:00Z'
            && str_contains((string) $request['parameter-name'], 'wind-speed-100m')
            && str_contains((string) $request['parameter-name'], 'wind-speed-150m')
            && ! array_key_exists('api-key', $request->data()));

        $cached = $service->forResolution($this->resolution());
        $this->assertSame($reading, $cached);
        Http::assertSentCount(2);
    }

    public function test_it_retries_a_busy_provider_twice_with_a_bound_and_fails_closed_without_cache(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://opendataapi.dmi.dk/v1/forecastedr/collections/harmonie_dini_sf/instances' => Http::response([
                'message' => 'Server is busy. Please try again later.',
            ], 429),
        ]);

        $reading = app(DmiForecastEdrService::class)->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        $this->assertFalse($reading['stale']);
        $this->assertSame(0, $reading['sample_count']);
        $this->assertStringContainsString('niet bereikbaar', $reading['availability_note']);
        Http::assertSentCount(2);
    }

    public function test_failed_refresh_exposes_last_good_only_as_stale(): void
    {
        $fail = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$fail) {
            if ($fail) {
                return Http::response(['message' => 'busy'], 429);
            }
            if (str_ends_with($request->url(), '/collections/harmonie_dini_sf/instances')) {
                return Http::response($this->instancesPayload());
            }

            return Http::response($this->positionPayload(5.1214, 52.0907));
        });

        $service = app(DmiForecastEdrService::class);
        $first = $service->forResolution($this->resolution());
        $this->assertTrue($first['complete']);
        $this->assertFalse($first['stale']);

        $fail = true;
        CarbonImmutable::setTestNow('2026-07-20T12:30:01Z');
        $fallback = $service->forResolution($this->resolution());

        $this->assertTrue($fallback['complete']);
        $this->assertTrue($fallback['stale']);
        $this->assertSame($first['valid_at'], $fallback['valid_at']);
        $this->assertNull($fallback['cloud_base_m']);
        $this->assertSame(0, $fallback['cloud_base_sample_count']);
        $this->assertFalse($fallback['cloud_base_complete']);
        $this->assertStringContainsString('niet bereikbaar', $fallback['availability_note']);
    }

    public function test_invalid_or_incomplete_dmi_values_never_become_a_complete_reading(): void
    {
        $payload = $this->positionPayload(5.1214, 52.0907);
        unset($payload['features'][2]['properties']['wind-speed-150m']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($payload) {
            if (str_ends_with($request->url(), '/collections/harmonie_dini_sf/instances')) {
                return Http::response($this->instancesPayload());
            }

            return Http::response($payload);
        });

        $reading = app(DmiForecastEdrService::class)->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        $this->assertSame(0, $reading['sample_count']);
        $this->assertArrayNotHasKey('wind_speed_kmh', $reading);
    }

    public function test_incomplete_upcoming_precipitation_outlook_fails_closed(): void
    {
        $payload = $this->positionPayload(5.1214, 52.0907);
        unset($payload['features'][5]['properties']['total-precipitation']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($payload) {
            if (str_ends_with($request->url(), '/collections/harmonie_dini_sf/instances')) {
                return Http::response($this->instancesPayload());
            }

            return Http::response($payload);
        });

        $reading = app(DmiForecastEdrService::class)->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        $this->assertSame(0, $reading['sample_count']);
        $this->assertArrayNotHasKey('forecast_precipitation_peak_mm_h', $reading);
        $this->assertNull(Cache::get('wallboard:uav-forecast:dmi:v3:selected-model-run:fresh'));
        $this->assertNull(Cache::get('wallboard:uav-forecast:dmi:v3:selected-model-run:last-good'));
    }

    public function test_lock_ttl_covers_the_worst_case_twelve_point_retry_budget(): void
    {
        config([
            'dis.wallboards.uav_forecast.timeout_seconds' => 20,
            'dis.wallboards.uav_forecast.dmi_retry_delay_ms' => 1000,
            'dis.wallboards.uav_forecast.dmi_lock_seconds' => 5,
        ]);

        $method = new \ReflectionMethod(DmiForecastEdrService::class, 'lockSeconds');
        $method->setAccessible(true);

        $this->assertSame(
            87,
            $method->invoke(app(DmiForecastEdrService::class), 12),
        );

        config(['dis.wallboards.uav_forecast.dmi_lock_seconds' => 900]);
        $this->assertSame(
            90,
            $method->invoke(app(DmiForecastEdrService::class), 12),
        );
    }

    public function test_it_skips_a_newest_run_whose_extent_does_not_cover_plus_three_hours(): void
    {
        $instances = [
            'instances' => [
                $this->instanceMetadata('2026-07-20T120000Z', $this->temporalValues(12, 14)),
                $this->instanceMetadata('2026-07-20T090000Z', $this->temporalValues(10, 16)),
            ],
        ];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($instances) {
            if (str_ends_with($request->url(), '/collections/harmonie_dini_sf/instances')) {
                return Http::response($instances);
            }

            return Http::response($this->positionPayload(5.1214, 52.0907));
        });

        $reading = app(DmiForecastEdrService::class)->forResolution($this->resolution());

        $this->assertTrue($reading['complete']);
        $this->assertSame('2026-07-20T09:00:00+00:00', $reading['model_run_at']);
        Http::assertNotSent(
            static fn (Request $request): bool => str_contains(
                $request->url(),
                '/instances/2026-07-20T120000Z/position',
            ),
        );
        Http::assertSent(
            static fn (Request $request): bool => str_contains(
                $request->url(),
                '/instances/2026-07-20T090000Z/position',
            ),
        );
    }

    public function test_it_falls_back_when_newest_extent_is_full_but_position_outlook_is_partial(): void
    {
        $instances = [
            'instances' => [
                $this->instanceMetadata('2026-07-20T120000Z', $this->temporalValues(12, 16)),
                $this->instanceMetadata('2026-07-20T090000Z', $this->temporalValues(10, 16)),
            ],
        ];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($instances) {
            if (str_ends_with($request->url(), '/collections/harmonie_dini_sf/instances')) {
                return Http::response($instances);
            }

            $payload = $this->positionPayload(5.1214, 52.0907);
            if (str_contains($request->url(), '/instances/2026-07-20T120000Z/position')) {
                unset($payload['features'][5]['properties']['total-precipitation']);
            }

            return Http::response($payload);
        });

        $reading = app(DmiForecastEdrService::class)->forResolution($this->resolution());

        $this->assertTrue($reading['complete']);
        $this->assertSame('2026-07-20T09:00:00+00:00', $reading['model_run_at']);
        $cachedRuns = Cache::get('wallboard:uav-forecast:dmi:v3:selected-model-run:fresh');
        $this->assertCount(1, $cachedRuns['candidates']);
        $this->assertSame(
            '2026-07-20T09:00:00+00:00',
            $cachedRuns['candidates'][0]['model_run_at'],
        );
        Http::assertSent(
            static fn (Request $request): bool => str_contains(
                $request->url(),
                '/instances/2026-07-20T120000Z/position',
            ),
        );
        Http::assertSent(
            static fn (Request $request): bool => str_contains(
                $request->url(),
                '/instances/2026-07-20T090000Z/position',
            ),
        );
    }

    public function test_cached_selected_run_keeps_an_older_candidate_for_another_resolution(): void
    {
        $instances = [
            'instances' => [
                $this->instanceMetadata('2026-07-20T120000Z', $this->temporalValues(12, 16)),
                $this->instanceMetadata('2026-07-20T090000Z', $this->temporalValues(10, 16)),
            ],
        ];
        $latestIsPartial = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($instances, &$latestIsPartial) {
            if (str_ends_with($request->url(), '/collections/harmonie_dini_sf/instances')) {
                return Http::response($instances);
            }

            [$longitude, $latitude] = $this->requestCoordinates($request);
            $payload = $this->positionPayload($longitude, $latitude);
            if ($latestIsPartial
                && str_contains($request->url(), '/instances/2026-07-20T120000Z/position')) {
                unset($payload['features'][5]['properties']['total-precipitation']);
            }

            return Http::response($payload);
        });

        $service = app(DmiForecastEdrService::class);
        $first = $service->forResolution($this->resolution());
        $this->assertSame('2026-07-20T12:00:00+00:00', $first['model_run_at']);

        $latestIsPartial = true;
        $second = $service->forResolution($this->resolution(52.2, 5.3));

        $this->assertTrue($second['complete']);
        $this->assertSame('2026-07-20T09:00:00+00:00', $second['model_run_at']);
        Http::assertSentCount(4);
    }

    public function test_provider_http_outage_does_not_repeat_the_same_failure_on_an_older_run(): void
    {
        $instances = [
            'instances' => [
                $this->instanceMetadata('2026-07-20T120000Z', $this->temporalValues(12, 16)),
                $this->instanceMetadata('2026-07-20T090000Z', $this->temporalValues(10, 16)),
            ],
        ];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($instances) {
            if (str_ends_with($request->url(), '/collections/harmonie_dini_sf/instances')) {
                return Http::response($instances);
            }

            return Http::response(['message' => 'busy'], 429);
        });

        $reading = app(DmiForecastEdrService::class)->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        Http::assertNotSent(
            static fn (Request $request): bool => str_contains(
                $request->url(),
                '/instances/2026-07-20T090000Z/position',
            ),
        );
        Http::assertSentCount(3);
    }

    public function test_invalid_temporal_extent_fails_closed_before_a_point_request(): void
    {
        $values = $this->temporalValues(10, 16);
        $values[2] = $values[1];
        Http::preventStrayRequests();
        Http::fake([
            'https://opendataapi.dmi.dk/v1/forecastedr/collections/harmonie_dini_sf/instances' => Http::response([
                'instances' => [$this->instanceMetadata('2026-07-20T090000Z', $values)],
            ]),
        ]);

        $reading = app(DmiForecastEdrService::class)->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        Http::assertSentCount(1);
    }

    public function test_twelve_point_cloud_base_is_unknown_when_one_sample_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/collections/harmonie_dini_sf/instances')) {
                return Http::response($this->instancesPayload());
            }

            $coordinates = (string) $request['coords'];
            if (preg_match('/\APOINT\((-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)\)\z/D', $coordinates, $matches) !== 1) {
                return Http::response([], 500);
            }
            $longitude = (float) $matches[1];
            $latitude = (float) $matches[2];
            $cloudBase = $longitude < 4.05 ? null : 850.0;

            return Http::response($this->positionPayload($longitude, $latitude, $cloudBase));
        });

        $reading = app(DmiForecastEdrService::class)->forResolution($this->provinceResolution());

        $this->assertTrue($reading['complete']);
        $this->assertFalse($reading['stale']);
        $this->assertSame(12, $reading['sample_count']);
        $this->assertNull($reading['cloud_base_m']);
        $this->assertSame(11, $reading['cloud_base_sample_count']);
        $this->assertSame(12, $reading['cloud_base_expected_sample_count']);
        $this->assertFalse($reading['cloud_base_complete']);
    }

    public function test_sun_times_use_the_amsterdam_operational_date_after_the_utc_day_boundary(): void
    {
        $method = new \ReflectionMethod(DmiForecastEdrService::class, 'sunTimes');
        $method->setAccessible(true);

        $sun = $method->invoke(
            app(DmiForecastEdrService::class),
            CarbonImmutable::parse('2026-07-20T22:30:00Z'),
            52.0907,
            5.1214,
        );

        $this->assertSame(
            '2026-07-21',
            CarbonImmutable::parse($sun['sunrise'])->setTimezone('Europe/Amsterdam')->toDateString(),
        );
        $this->assertSame(
            '2026-07-21',
            CarbonImmutable::parse($sun['sunset'])->setTimezone('Europe/Amsterdam')->toDateString(),
        );
    }

    /** @return array<string, mixed> */
    private function resolution(
        float $latitude = 52.0907,
        float $longitude = 5.1214,
    ): array {
        return [
            'mode' => 'address',
            'label' => 'Utrecht, Nederland',
            'locations' => [[
                'label' => 'Utrecht, Nederland',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]],
            'expected_locations' => 1,
            'complete' => true,
        ];
    }

    /** @return array{0: float, 1: float} */
    private function requestCoordinates(Request $request): array
    {
        $coordinates = (string) $request['coords'];
        $this->assertSame(
            1,
            preg_match(
                '/\APOINT\((-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)\)\z/D',
                $coordinates,
                $matches,
            ),
        );

        return [(float) $matches[1], (float) $matches[2]];
    }

    /** @return array<string, mixed> */
    private function provinceResolution(): array
    {
        $locations = [];
        for ($index = 0; $index < 12; $index++) {
            $locations[] = [
                'label' => 'Provincie '.($index + 1),
                'latitude' => 51.0 + ($index * 0.1),
                'longitude' => 4.0 + ($index * 0.1),
            ];
        }

        return [
            'mode' => 'national',
            'label' => 'Nederland',
            'locations' => $locations,
            'expected_locations' => 12,
            'complete' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function instancesPayload(): array
    {
        $values = $this->temporalValues(10, 16);

        return [
            'instances' => [
                $this->instanceMetadata('2026-07-20T060000Z', $values),
                $this->instanceMetadata('2026-07-20T090000Z', $values),
                $this->instanceMetadata('2026-07-20T150000Z', $this->temporalValues(15, 20)),
            ],
        ];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private function instanceMetadata(string $id, array $values): array
    {
        return [
            'id' => $id,
            'extent' => [
                'temporal' => [
                    'interval' => [[$values[0], $values[array_key_last($values)]]],
                    'values' => $values,
                ],
            ],
        ];
    }

    /** @return list<string> */
    private function temporalValues(int $firstHour, int $lastHour): array
    {
        $values = [];
        for ($hour = $firstHour; $hour <= $lastHour; $hour++) {
            $values[] = sprintf('2026-07-20T%02d:00:00Z', $hour);
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function positionPayload(
        float $longitude,
        float $latitude,
        ?float $cloudBase = 850.0,
    ): array {
        $steps = [
            ['2026-07-20T10:00:00Z', 0.0, 0.0, 0.0],
            ['2026-07-20T11:00:00Z', 0.1, 0.0, 0.0],
            ['2026-07-20T12:00:00Z', 0.3, 0.2 / 3600, 0.0],
            ['2026-07-20T13:00:00Z', 0.4, 0.1 / 3600, 0.15],
            ['2026-07-20T14:00:00Z', 0.9, 0.5 / 3600, 0.05],
            ['2026-07-20T15:00:00Z', 0.9, 0.0, 0.0],
            ['2026-07-20T16:00:00Z', 1.0, 0.1 / 3600, 0.0],
        ];

        return [
            'type' => 'FeatureCollection',
            'features' => array_map(
                static fn (array $step): array => [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [$longitude, $latitude],
                    ],
                    'properties' => [
                        'step' => $step[0],
                        'temperature-2m' => 280.0,
                        'dew-point-temperature-2m' => 275.0,
                        'wind-speed-10m' => 5.0,
                        'wind-speed-100m' => 8.0,
                        'wind-speed-150m' => 10.0,
                        'wind-dir-100m' => 90.0,
                        'gust-wind-speed-10m' => 12.0,
                        'total-precipitation' => $step[1],
                        'rain-precipitation-rate' => $step[2],
                        'visibility' => 12000.0,
                        'fraction-of-cloud-cover' => 0.4,
                        'low-cloud-cover' => 20.0,
                        'medium-cloud-cover' => 30.0,
                        'high-cloud-cover' => 50.0,
                        'cloud-base' => $cloudBase,
                        'probability-of-lightning' => $step[3],
                        'land-percent' => 1.0,
                    ],
                ],
                $steps,
            ),
        ];
    }
}
