<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\KnmiCloudBaseObservationService;
use App\Services\WallboardForecastLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KnmiCloudBaseObservationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-20T12:15:00Z');
        Cache::flush();
        config([
            'dis.wallboards.uav_forecast.knmi_edr_api_key' => 'knmi-test-key',
            'dis.wallboards.uav_forecast.cache_seconds' => 900,
            'dis.wallboards.uav_forecast.last_good_cache_seconds' => 21600,
            'dis.wallboards.uav_forecast.cloud_base_station_cache_seconds' => 86400,
            'dis.wallboards.uav_forecast.cloud_base_stale_seconds' => 1800,
            'dis.wallboards.uav_forecast.cloud_base_max_distance_km' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_selects_nearest_valid_station_converts_feet_and_caches_edr_responses(): void
    {
        Http::preventStrayRequests();
        $this->fakeKnmi(
            stations: [
                $this->station('station-near', 'De Bilt', 5.1300, 52.1000),
                $this->station('station-farther', 'Lelystad', 5.4000, 52.2000),
            ],
            coverages: [
                $this->coverage('station-near', '2026-07-20T12:10:00Z', [
                    'hc1' => 1000,
                    'nc1' => 4,
                    'hc2' => 3000,
                    'nc2' => 7,
                    'hc3' => 0,
                    'nc3' => 0,
                ]),
                $this->coverage('station-farther', '2026-07-20T12:10:00Z', [
                    'hc1' => 200,
                    'nc1' => 8,
                    'hc2' => 0,
                    'nc2' => 0,
                    'hc3' => 0,
                    'nc3' => 0,
                ]),
            ],
        );

        $first = $this->service()->forResolution($this->addressResolution());
        $cached = $this->service()->forResolution($this->addressResolution());

        $this->assertSame('measured', $first['status']);
        $this->assertSame(305, $first['base_height_m']);
        $this->assertSame('mean_sea_level', $first['height_reference']);
        $this->assertSame([
            ['height_m' => 305, 'cover_okta' => 4],
            ['height_m' => 914, 'cover_okta' => 7],
        ], $first['layers']);
        $this->assertSame('station-near', $first['station']['id']);
        $this->assertSame('De Bilt', $first['station']['name']);
        $this->assertLessThan(2.0, $first['station']['distance_km']);
        $this->assertSame('2026-07-20T12:10:00+00:00', $first['observed_at']);
        $this->assertSame(30, $first['period_minutes']);
        $this->assertSame('KNMI', $first['attribution']);
        $this->assertSame($first, $cached);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/locations?bbox=3.2%2C50.7%2C7.3%2C53.7&datetime=2026-07-20T11%3A35%3A00%2B00%3A00%2F2026-07-20T12%3A15%3A00%2B00%3A00')
            && $request->hasHeader('Authorization', 'knmi-test-key'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/cube?')
            && $request->hasHeader('Authorization', 'knmi-test-key')
            && $request['f'] === 'CoverageJSON'
            && str_contains((string) $request['parameter-name'], 'hc1')
            && str_contains((string) $request['parameter-name'], 'nc3'));
        $this->assertStringNotContainsString('knmi-test-key', json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function test_zero_height_and_zero_okta_are_a_valid_no_cloud_observation(): void
    {
        Http::preventStrayRequests();
        $this->fakeKnmi(
            [$this->station('station-clear', 'Voorschoten', 5.13, 52.10)],
            [$this->coverage('station-clear', '2026-07-20T12:10:00Z', [
                'hc1' => 0,
                'nc1' => 0,
                'hc2' => 0,
                'nc2' => 0,
                'hc3' => 0,
                'nc3' => 0,
            ])],
        );

        $observation = $this->service()->forResolution($this->addressResolution());

        $this->assertSame('no_cloud_detected', $observation['status']);
        $this->assertNull($observation['base_height_m']);
        $this->assertSame([], $observation['layers']);
        $this->assertSame('station-clear', $observation['station']['id']);
        $this->assertSame('2026-07-20T12:10:00+00:00', $observation['observed_at']);
    }

    public function test_stale_observation_fails_closed(): void
    {
        Http::preventStrayRequests();
        $this->fakeKnmi(
            [$this->station('station-stale', 'De Bilt', 5.13, 52.10)],
            [$this->coverage('station-stale', '2026-07-20T11:44:59Z', [
                'hc1' => 500,
                'nc1' => 8,
            ])],
        );

        $this->assertSame($this->unknownObservation(), $this->service()->forResolution($this->addressResolution()));
    }

    public function test_special_or_out_of_range_okta_value_fails_closed(): void
    {
        Http::preventStrayRequests();
        $this->fakeKnmi(
            [$this->station('station-obscured', 'De Bilt', 5.13, 52.10)],
            [$this->coverage('station-obscured', '2026-07-20T12:10:00Z', [
                'hc1' => 500,
                // KNMI documents these cloud-layer values as 0 through 8 okta.
                // A special value such as 9 is not interpreted as ordinary cover.
                'nc1' => 9,
            ])],
        );

        $this->assertSame($this->unknownObservation(), $this->service()->forResolution($this->addressResolution()));
    }

    public function test_station_beyond_maximum_distance_fails_closed(): void
    {
        Http::preventStrayRequests();
        $this->fakeKnmi(
            [$this->station('station-far', 'Ver station', 6.10, 52.10)],
            [$this->coverage('station-far', '2026-07-20T12:10:00Z', [
                'hc1' => 500,
                'nc1' => 8,
            ])],
        );

        $this->assertSame($this->unknownObservation(), $this->service()->forResolution($this->addressResolution()));
    }

    public function test_missing_fresh_caches_fall_back_to_last_good_during_provider_failure(): void
    {
        config([
            'dis.wallboards.uav_forecast.cache_seconds' => 60,
            'dis.wallboards.uav_forecast.last_good_cache_seconds' => 1,
            'dis.wallboards.uav_forecast.cloud_base_station_cache_seconds' => 60,
        ]);
        Http::preventStrayRequests();
        $this->fakeKnmi(
            [$this->station('station-near', 'De Bilt', 5.13, 52.10)],
            [$this->coverage('station-near', '2026-07-20T12:10:00Z', [
                'hc1' => 1000,
                'nc1' => 4,
            ])],
        );

        $expected = $this->service()->forResolution($this->addressResolution());

        $this->assertTrue(Cache::has('wallboard:uav-forecast:knmi-cloud-base:v1:stations:fresh'));
        $this->assertTrue(Cache::has('wallboard:uav-forecast:knmi-cloud-base:v1:observations:fresh'));
        Cache::forget('wallboard:uav-forecast:knmi-cloud-base:v1:stations:fresh');
        Cache::forget('wallboard:uav-forecast:knmi-cloud-base:v1:observations:fresh');
        $this->assertFalse(Cache::has('wallboard:uav-forecast:knmi-cloud-base:v1:stations:fresh'));
        $this->assertFalse(Cache::has('wallboard:uav-forecast:knmi-cloud-base:v1:observations:fresh'));
        Http::fake([
            'https://api.dataplatform.knmi.nl/edr/v1/collections/10-minute-in-situ-meteorological-observations/*' => Http::response([], 500),
        ]);

        $this->assertSame($expected, $this->service()->forResolution($this->addressResolution()));
        Http::assertSentCount(2);
    }

    public function test_missing_api_key_returns_unknown_without_an_external_request(): void
    {
        config(['dis.wallboards.uav_forecast.knmi_edr_api_key' => null]);
        Http::preventStrayRequests();

        $this->assertSame($this->unknownObservation(), $this->service()->forResolution($this->addressResolution()));
        Http::assertNothingSent();
    }

    public function test_admin_managed_api_key_takes_precedence_over_the_environment_fallback(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'weather.knmi_edr_api_key'],
            ['value' => 'admin-managed-knmi-key', 'is_sensitive' => true],
        );
        Http::preventStrayRequests();
        $this->fakeKnmi(
            [$this->station('station-near', 'De Bilt', 5.13, 52.10)],
            [$this->coverage('station-near', '2026-07-20T12:10:00Z', [
                'hc1' => 1000,
                'nc1' => 4,
            ])],
        );

        try {
            $this->assertSame(
                'measured',
                $this->service()->forResolution($this->addressResolution())['status'],
            );
            Http::assertSentCount(2);
            Http::assertSent(fn (Request $request): bool => $request->hasHeader(
                'Authorization',
                'admin-managed-knmi-key',
            ));
        } finally {
            SystemSetting::query()->whereKey('weather.knmi_edr_api_key')->delete();
        }
    }

    public function test_malformed_edr_data_returns_unknown_without_leaking_details(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.dataplatform.knmi.nl/edr/v1/collections/10-minute-in-situ-meteorological-observations/locations*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [$this->station('station-near', 'De Bilt', 5.13, 52.10)],
            ]),
            'https://api.dataplatform.knmi.nl/edr/v1/collections/10-minute-in-situ-meteorological-observations/cube*' => Http::response([
                'type' => 'CoverageCollection',
                'coverages' => 'invalid',
                'error' => 'provider-internal-detail',
            ]),
        ]);

        $observation = $this->service()->forResolution($this->addressResolution());

        $this->assertSame($this->unknownObservation(), $observation);
        $this->assertStringNotContainsString('provider-internal-detail', json_encode($observation, JSON_THROW_ON_ERROR));
    }

    public function test_malformed_coverage_axes_and_shape_fail_closed(): void
    {
        Http::preventStrayRequests();
        $coverage = $this->coverage('station-near', '2026-07-20T12:10:00Z', [
            'hc1' => 500,
            'nc1' => 8,
        ]);
        $coverage['ranges']['hc1']['shape'] = [2, 1, 1];
        $this->fakeKnmi(
            [$this->station('station-near', 'De Bilt', 5.13, 52.10)],
            [$coverage],
        );

        $this->assertSame($this->unknownObservation(), $this->service()->forResolution($this->addressResolution()));
    }

    public function test_national_aggregation_never_claims_one_station(): void
    {
        Http::preventStrayRequests();

        $observation = $this->service()->forResolution([
            'mode' => WallboardForecastLocationService::MODE_NETHERLANDS,
            'complete' => true,
            'locations' => (array) config('dis.wallboards.uav_forecast.province_reference_points'),
        ]);

        $this->assertSame($this->unknownObservation(), $observation);
        Http::assertNothingSent();
    }

    private function service(): KnmiCloudBaseObservationService
    {
        return app(KnmiCloudBaseObservationService::class);
    }

    /** @return array<string, mixed> */
    private function addressResolution(): array
    {
        return [
            'mode' => WallboardForecastLocationService::MODE_ADDRESS,
            'complete' => true,
            'locations' => [[
                'label' => 'Utrecht, Nederland',
                'latitude' => 52.0907,
                'longitude' => 5.1214,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function station(string $id, string $name, float $longitude, float $latitude): array
    {
        return [
            'type' => 'Feature',
            'id' => $id,
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$longitude, $latitude],
            ],
            'properties' => ['name' => $name],
        ];
    }

    /**
     * @param  array<string, int|float|null>  $values
     * @return array<string, mixed>
     */
    private function coverage(string $stationId, string $observedAt, array $values): array
    {
        $ranges = [];
        foreach ($values as $parameter => $value) {
            $ranges[$parameter] = [
                'type' => 'NdArray',
                'dataType' => 'float',
                'axisNames' => ['t', 'y', 'x'],
                'shape' => [1, 1, 1],
                'values' => [$value],
            ];
        }

        return [
            'type' => 'Coverage',
            'domain' => [
                'type' => 'Domain',
                'domainType' => 'PointSeries',
                'axes' => [
                    'x' => ['values' => [5.13]],
                    'y' => ['values' => [52.10]],
                    't' => ['values' => [$observedAt]],
                ],
            ],
            'ranges' => $ranges,
            'eumetnet:locationId' => $stationId,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stations
     * @param  list<array<string, mixed>>  $coverages
     */
    private function fakeKnmi(array $stations, array $coverages): void
    {
        Http::fake([
            'https://api.dataplatform.knmi.nl/edr/v1/collections/10-minute-in-situ-meteorological-observations/locations*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => $stations,
            ]),
            'https://api.dataplatform.knmi.nl/edr/v1/collections/10-minute-in-situ-meteorological-observations/cube*' => Http::response([
                'type' => 'CoverageCollection',
                'domainType' => 'PointSeries',
                'coverages' => $coverages,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function unknownObservation(): array
    {
        return [
            'status' => 'unknown',
            'base_height_m' => null,
            'height_reference' => 'mean_sea_level',
            'layers' => [],
            'station' => null,
            'observed_at' => null,
            'period_minutes' => 30,
            'attribution' => 'KNMI',
        ];
    }
}
