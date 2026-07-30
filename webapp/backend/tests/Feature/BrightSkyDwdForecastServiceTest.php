<?php

namespace Tests\Feature;

use App\Services\BrightSkyDwdForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BrightSkyDwdForecastServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-20T12:15:00Z');
        Cache::flush();
        config([
            'dis.wallboards.uav_forecast.cache_seconds' => 900,
            'dis.wallboards.uav_forecast.last_good_cache_seconds' => 21600,
            'dis.wallboards.uav_forecast.weather_stale_seconds' => 1800,
            'dis.wallboards.uav_forecast.bright_sky_retry_delay_ms' => 50,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_returns_a_complete_live_single_point_dwd_forecast(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response(
            $this->weatherPayload(
                latitude: (float) $request['lat'],
                longitude: (float) $request['lon'],
            ),
        ));
        $filesBefore = Storage::disk('local')->allFiles();

        $service = app(BrightSkyDwdForecastService::class);
        $reading = $service->forResolution($this->resolution());

        $this->assertTrue($reading['complete']);
        $this->assertFalse($reading['stale']);
        $this->assertSame('dwd_mosmix_bright_sky', $reading['provider_identifier']);
        $this->assertSame('DWD_MOSMIX', $reading['structured_attribution']);
        $this->assertSame('DWD MOSMIX via Bright Sky', $reading['source']['name']);
        $this->assertSame('CC BY 4.0', $reading['source']['license']);
        $this->assertStringContainsString('Deutscher Wetterdienst', $reading['source']['attribution']);
        $this->assertStringContainsString('10 m AGL', $reading['source']['processing_note']);
        $this->assertSame(1, $reading['sample_count']);
        $this->assertSame(1, $reading['expected_sample_count']);
        $this->assertSame(0, $reading['weather_code']);
        $this->assertSame(20.0, $reading['temperature_c']);
        $this->assertSame(10.0, $reading['wind_speed_10m_kmh']);
        $this->assertSame(10.0, $reading['wind_speed_kmh']);
        $this->assertNull($reading['wind_speed_100m_kmh']);
        $this->assertNull($reading['wind_speed_150m_kmh']);
        $this->assertSame(10, $reading['wind_reference_height_agl_m']);
        $this->assertSame(40.0, $reading['precipitation_probability_pct']);
        $this->assertSame(0.4, $reading['precipitation_mm']);
        $this->assertSame(0.4, $reading['precipitation_rate_mm_h']);
        $this->assertSame(3.0, $reading['forecast_precipitation_peak_mm_h']);
        $this->assertSame(
            '2026-07-20T13:00:00+00:00',
            $reading['forecast_precipitation_first_at'],
        );
        $this->assertSame(
            '2026-07-20T16:00:00+00:00',
            $reading['forecast_precipitation_until'],
        );
        $this->assertSame(
            '2026-07-20T15:00:00+00:00',
            $reading['forecast_precipitation_third_hour_from'],
        );
        $this->assertSame(
            80.0,
            $reading['forecast_precipitation_third_hour_probability_pct'],
        );
        $this->assertTrue($reading['thunderstorm_expected']);
        $this->assertNull($reading['thunderstorm_probability_pct']);
        $this->assertSame(
            '2026-07-20T15:00:00+00:00',
            $reading['thunderstorm_first_expected_at'],
        );
        $this->assertNull($reading['model_run_at']);
        $this->assertSame('2026-07-20T13:00:00+00:00', $reading['valid_at']);
        $this->assertNull($reading['cloud_cover_low_pct']);
        $this->assertNull($reading['cloud_base_m']);
        $this->assertSame(0, $reading['cloud_base_expected_sample_count']);
        $this->assertFalse($reading['cloud_base_complete']);
        $this->assertIsString($reading['sunrise_earliest']);
        $this->assertIsString($reading['sunset_latest']);
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles());

        Http::assertSent(function (Request $request): bool {
            return str_starts_with(
                $request->url(),
                'https://api.brightsky.dev/weather?',
            )
                && $request['date'] === '2026-07-20T13:00:00Z'
                && $request['last_date'] === '2026-07-20T16:00:00Z'
                && $request['max_dist'] === 150000
                && $request['tz'] === 'UTC'
                && $request['units'] === 'dwd'
                && str_contains(
                    (string) $request->header('User-Agent')[0],
                    'DIS-UAV-Weather',
                );
        });

        $cached = $service->forResolution($this->resolution());
        $this->assertSame($reading, $cached);
        Http::assertSentCount(1);
    }

    public function test_it_aggregates_exactly_twelve_province_points(): void
    {
        $requestCount = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$requestCount) {
            $index = $requestCount++;
            $payload = $this->weatherPayload(
                latitude: (float) $request['lat'],
                longitude: (float) $request['lon'],
                temperature: 10.0 + $index,
                windDirection: $index % 2 === 0 ? 350.0 : 10.0,
            );
            if ($index === 11) {
                $payload['weather'][0]['condition'] = 'hail';
                $payload['weather'][0]['icon'] = 'hail';
            }

            return Http::response($payload);
        });

        $reading = app(BrightSkyDwdForecastService::class)
            ->forResolution($this->provinceResolution());

        $this->assertTrue($reading['complete']);
        $this->assertFalse($reading['stale']);
        $this->assertSame(12, $reading['sample_count']);
        $this->assertSame(12, $reading['expected_sample_count']);
        $this->assertSame('DWD MOSMIX via Bright Sky (12 provincies)', $reading['source']['name']);
        $this->assertEqualsWithDelta(15.5, $reading['temperature_c'], 0.001);
        $this->assertTrue(
            $reading['wind_direction_degrees'] < 1
                || $reading['wind_direction_degrees'] > 359,
        );
        $this->assertSame(77, $reading['weather_code']);
        $this->assertSame(0, $reading['cloud_base_sample_count']);
        $this->assertSame(0, $reading['cloud_base_expected_sample_count']);
        $this->assertFalse($reading['cloud_base_complete']);
        $this->assertSame(12, $requestCount);
        Http::assertSentCount(12);
    }

    public function test_missing_required_weather_value_fails_closed(): void
    {
        $payload = $this->weatherPayload();
        unset($payload['weather'][2]['precipitation_probability']);
        Http::preventStrayRequests();
        Http::fake([self::weatherUrlPattern() => Http::response($payload)]);

        $reading = app(BrightSkyDwdForecastService::class)
            ->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        $this->assertFalse($reading['stale']);
        $this->assertSame(0, $reading['sample_count']);
        $this->assertSame(1, $reading['expected_sample_count']);
    }

    public function test_malformed_json_fails_closed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::weatherUrlPattern() => Http::response(
                '{"weather":',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $reading = app(BrightSkyDwdForecastService::class)
            ->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        $this->assertStringContainsString('ongeldige modeldata', $reading['availability_note']);
        Http::assertSentCount(1);
    }

    public function test_not_found_is_not_retried(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::weatherUrlPattern() => Http::response(['detail' => 'Not found'], 404),
        ]);

        $reading = app(BrightSkyDwdForecastService::class)
            ->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        Http::assertSentCount(1);
    }

    public function test_rate_limit_is_retried_once_then_fails_closed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::weatherUrlPattern() => Http::response(['detail' => 'Busy'], 429),
        ]);

        $reading = app(BrightSkyDwdForecastService::class)
            ->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        Http::assertSentCount(2);
    }

    public function test_invalid_source_metadata_fails_closed(): void
    {
        $payload = $this->weatherPayload();
        $payload['sources'][0]['observation_type'] = 'current';
        Http::preventStrayRequests();
        Http::fake([self::weatherUrlPattern() => Http::response($payload)]);

        $reading = app(BrightSkyDwdForecastService::class)
            ->forResolution($this->resolution());

        $this->assertFalse($reading['complete']);
        $this->assertSame(0, $reading['sample_count']);
    }

    public function test_failed_refresh_returns_last_good_only_as_stale(): void
    {
        $fail = false;
        Http::preventStrayRequests();
        Http::fake(function () use (&$fail) {
            return $fail
                ? Http::response(['detail' => 'Busy'], 429)
                : Http::response($this->weatherPayload());
        });

        $service = app(BrightSkyDwdForecastService::class);
        $first = $service->forResolution($this->resolution());
        $this->assertTrue($first['complete']);
        $this->assertFalse($first['stale']);

        $fail = true;
        CarbonImmutable::setTestNow('2026-07-20T12:30:01Z');
        $fallback = $service->forResolution($this->resolution());

        $this->assertTrue($fallback['complete']);
        $this->assertTrue($fallback['stale']);
        $this->assertSame($first['valid_at'], $fallback['valid_at']);
        $this->assertStringContainsString('niet bereikbaar', $fallback['availability_note']);
        Http::assertSentCount(3);
    }

    public function test_incomplete_or_duplicate_resolution_is_rejected_without_http(): void
    {
        Http::preventStrayRequests();

        $incomplete = $this->resolution();
        $incomplete['complete'] = false;
        $duplicate = $this->resolution();
        $duplicate['expected_locations'] = 2;
        $duplicate['locations'][] = $duplicate['locations'][0];

        $service = app(BrightSkyDwdForecastService::class);
        $this->assertFalse($service->forResolution($incomplete)['complete']);
        $this->assertFalse($service->forResolution($duplicate)['complete']);
        Http::assertNothingSent();
    }

    private static function weatherUrlPattern(): string
    {
        return 'https://api.brightsky.dev/weather*';
    }

    /** @return array<string, mixed> */
    private function resolution(): array
    {
        return [
            'mode' => 'address',
            'label' => 'Utrecht, Nederland',
            'locations' => [[
                'label' => 'Utrecht, Nederland',
                'latitude' => 52.0907,
                'longitude' => 5.1214,
            ]],
            'expected_locations' => 1,
            'complete' => true,
        ];
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
    private function weatherPayload(
        float $latitude = 52.0907,
        float $longitude = 5.1214,
        float $temperature = 20.0,
        float $windDirection = 350.0,
    ): array {
        $steps = [
            ['2026-07-20T13:00:00+00:00', 0.0, 10, 'dry', 'clear-day'],
            ['2026-07-20T14:00:00+00:00', 0.4, 40, 'rain', 'rain'],
            ['2026-07-20T15:00:00+00:00', 3.0, 60, 'rain', 'rain'],
            ['2026-07-20T16:00:00+00:00', 0.2, 80, 'thunderstorm', 'thunderstorm'],
        ];

        return [
            'weather' => array_map(
                static fn (array $step): array => [
                    'timestamp' => $step[0],
                    'source_id' => 100,
                    'precipitation' => $step[1],
                    'temperature' => $temperature,
                    'wind_direction' => $windDirection,
                    'wind_speed' => 10.0,
                    'cloud_cover' => 30.0,
                    'dew_point' => 12.0,
                    'visibility' => 15000.0,
                    'wind_gust_speed' => 20.0,
                    'condition' => $step[3],
                    'precipitation_probability' => $step[2],
                    'fallback_source_ids' => ['visibility' => 101],
                    'icon' => $step[4],
                ],
                $steps,
            ),
            'sources' => [
                [
                    'id' => 100,
                    'observation_type' => 'forecast',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'first_record' => '2026-07-20T10:00:00+00:00',
                    'last_record' => '2026-07-21T10:00:00+00:00',
                    'distance' => 0,
                ],
                [
                    'id' => 101,
                    'observation_type' => 'forecast',
                    'lat' => $latitude + 0.01,
                    'lon' => $longitude + 0.01,
                    'first_record' => '2026-07-20T10:00:00+00:00',
                    'last_record' => '2026-07-21T10:00:00+00:00',
                    'distance' => 1300,
                ],
            ],
        ];
    }
}
