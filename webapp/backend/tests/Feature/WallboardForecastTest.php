<?php

namespace Tests\Feature;

use App\Services\WallboardForecastClassifier;
use App\Services\WallboardForecastService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WallboardForecastTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_classifier_is_fail_closed_and_uses_the_worst_factor(): void
    {
        $classifier = app(WallboardForecastClassifier::class);

        $this->assertSame('green', $classifier->classify('wind_speed_kmh', 20)['status']);
        $this->assertSame('orange', $classifier->classify('wind_speed_kmh', 20.1)['status']);
        $this->assertSame('red', $classifier->classify('visibility_m', 1999)['status']);
        $this->assertSame('orange', $classifier->classify('kp_index', 4)['status']);
        $this->assertSame('red', $classifier->classify('kp_index', 6)['status']);
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

    public function test_forecast_uses_fixed_authoritative_sources_and_exposes_time_staleness_and_gnss_unknown(): void
    {
        CarbonImmutable::setTestNow('2026-07-19T12:15:00Z');
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response([
                'current' => [
                    'time' => '2026-07-19T12:00:00Z',
                    'wind_speed_10m' => 15,
                    'wind_gusts_10m' => 35,
                    'precipitation' => 0,
                    'visibility' => 1500,
                ],
            ]),
            'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json' => Http::response([
                ['time_tag', 'Kp'],
                ['2026-07-19T09:00:00Z', '3.0'],
                ['2026-07-19T12:00:00Z', '4.3'],
            ]),
        ]);

        $pages = app(WallboardForecastService::class)->pages([
            'pages' => [$this->page()],
        ]);
        $forecast = $pages['forecast-utrecht'];
        $metrics = collect($forecast['metrics'])->keyBy('key');

        $this->assertSame('red', $forecast['overall_status']);
        $this->assertSame('green', $metrics['wind_speed_kmh']['status']);
        $this->assertSame('orange', $metrics['wind_gust_kmh']['status']);
        $this->assertSame('red', $metrics['visibility_m']['status']);
        $this->assertSame('orange', $metrics['kp_index']['status']);
        $this->assertSame('unknown', $metrics['gnss_satellites']['status']);
        $this->assertSame('Open-Meteo', $metrics['wind_speed_kmh']['source']['name']);
        $this->assertSame('NOAA SWPC', $metrics['kp_index']['source']['name']);
        $this->assertSame('2026-07-19T12:00:00+00:00', $metrics['wind_speed_kmh']['measured_at']);
        $this->assertFalse($metrics['wind_speed_kmh']['stale']);
        $this->assertStringContainsString('Toestellimieten', $forecast['disclaimer']);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json');
    }

    public function test_unavailable_or_stale_sources_never_produce_green(): void
    {
        CarbonImmutable::setTestNow('2026-07-19T12:15:00Z');
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.open-meteo.com/v1/forecast*' => Http::response([
                'current' => [
                    'time' => '2026-07-19T06:00:00Z',
                    'wind_speed_10m' => 5,
                    'wind_gusts_10m' => 5,
                    'precipitation' => 0,
                    'visibility' => 10000,
                ],
            ]),
            'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json' => Http::response([], 503),
        ]);

        $forecast = app(WallboardForecastService::class)->pages(['pages' => [$this->page()]])['forecast-utrecht'];

        $this->assertSame('unknown', $forecast['overall_status']);
        foreach ($forecast['metrics'] as $metric) {
            $this->assertNotSame('green', $metric['status']);
        }
    }

    public function test_forecast_configuration_requires_a_bounded_location_and_rejects_unknown_options(): void
    {
        $normalized = WallboardConfiguration::normalize(['pages' => [$this->page()]]);
        $this->assertSame([
            'location_label' => 'Utrecht',
            'latitude' => 52.0907,
            'longitude' => 5.1214,
        ], $normalized['pages'][0]['options']);

        $invalid = $this->page();
        $invalid['options']['latitude'] = 91;
        try {
            WallboardConfiguration::normalize(['pages' => [$invalid]]);
            $this->fail('Ongeldige forecastcoördinaten hadden niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.pages.0.options.latitude', $exception->errors());
        }

        $invalid = $this->page();
        $invalid['options']['provider_url'] = 'https://attacker.example/forecast';
        try {
            WallboardConfiguration::normalize(['pages' => [$invalid]]);
            $this->fail('Een door de client gekozen forecastprovider had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.pages.0.options', $exception->errors());
        }
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        return [
            'id' => 'forecast-utrecht',
            'name' => 'UAV Forecast',
            'type' => 'uav_forecast',
            'duration_seconds' => 30,
            'options' => [
                'location_label' => 'Utrecht',
                'latitude' => 52.0907,
                'longitude' => 5.1214,
            ],
        ];
    }
}
