<?php

namespace Tests\Feature;

use App\Contracts\UavWeatherForecastProvider;
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
    private StubUavWeatherForecastProvider $weatherForecasts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->weatherForecasts = new StubUavWeatherForecastProvider;
        $this->app->instance(UavWeatherForecastProvider::class, $this->weatherForecasts);
    }

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
        $this->assertSame('red', $classifier->classify('precipitation_rate_mm_h', 0.6)['status']);
        $this->assertSame('orange', $classifier->classify('cloud_cover_pct', 75)['status']);
        $this->assertSame('green', $classifier->classify('low_cloud_cover_pct', 50)['status']);
        $this->assertSame('orange', $classifier->classify('low_cloud_cover_pct', 75)['status']);
        $this->assertSame('red', $classifier->classify('low_cloud_cover_pct', 86)['status']);
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
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'cloud_cover_pct' => 100.0,
            'cloud_cover_low_pct' => 20.0,
            'cloud_cover_mid_pct' => 60.0,
            'cloud_cover_high_pct' => 80.0,
            'visibility_m' => 9000.0,
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
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
        $this->assertSame('Onbekend', $forecast['condition']['label']);
        $this->assertSame($forecast['daylight']['sunrise_earliest'], $forecast['daylight']['sunrise_latest']);
        $this->assertSame('Europe/Amsterdam', $forecast['daylight']['timezone']);
        $this->assertSame(100, $metrics['wind_speed_kmh']['altitude_m']);
        $this->assertSame('100 m boven maaiveld', $metrics['wind_speed_kmh']['source_height_label']);
        $this->assertSame(100, $metrics['wind_speed_kmh']['max_non_red_wind_height_agl_m']);
        $this->assertSame([
            ['height_agl_m' => 10, 'speed_kmh' => 10.0],
            ['height_agl_m' => 100, 'speed_kmh' => 25.0],
            ['height_agl_m' => 150, 'speed_kmh' => 35.0],
        ], $metrics['wind_speed_kmh']['height_samples_agl_m']);
        $this->assertSame(10, $metrics['wind_gust_kmh']['altitude_m']);
        $this->assertSame(100.0, $metrics['cloud_cover_pct']['value']);
        $this->assertSame('Totale modelbewolking', $metrics['cloud_cover_pct']['label']);
        $this->assertNull($metrics['cloud_cover_pct']['altitude_m']);
        $this->assertSame('Volledige hemelkolom volgens DMI HARMONIE DINI', $metrics['cloud_cover_pct']['source_height_label']);
        $this->assertSame(20.0, $metrics['low_cloud_cover_pct']['value']);
        $this->assertSame('green', $metrics['low_cloud_cover_pct']['status']);
        $this->assertNull($metrics['low_cloud_cover_pct']['altitude_m']);
        $this->assertSame(
            'DMI HARMONIE DINI-categorie lage bewolking; geen vaste hoogteband',
            $metrics['low_cloud_cover_pct']['source_height_label'],
        );
        $this->assertSame([
            'low_pct' => 20.0,
            'mid_pct' => 60.0,
            'high_pct' => 80.0,
            'total_pct' => 100.0,
        ], $metrics['low_cloud_cover_pct']['cloud_layers']);
        $this->assertSame([
            'status' => 'forecast',
            'base_height_m' => 850.0,
            'height_reference' => 'model_unspecified',
            'aggregation' => 'single_grid_point',
            'sample_count' => 1,
            'expected_sample_count' => 1,
            'model_run_at' => '2026-07-20T09:00:00+00:00',
            'valid_at' => '2026-07-20T12:00:00+00:00',
            'attribution' => 'DMI_HARMONIE',
        ], $metrics['low_cloud_cover_pct']['cloud_base_forecast']);
        $this->assertSame([
            'status' => 'unknown',
            'base_height_m' => null,
            'height_reference' => 'mean_sea_level',
            'layers' => [],
            'station' => null,
            'observed_at' => null,
            'period_minutes' => 30,
            'attribution' => 'KNMI',
        ], $metrics['low_cloud_cover_pct']['cloud_base_observation']);
        $this->assertSame('9000', $metrics['visibility_m']['display_value']);
        $this->assertSame('m', $metrics['visibility_m']['display_unit']);
        $this->assertSame('NOAA SWPC Kp (1 minuut)', $metrics['kp_index']['source']['name']);
        $this->assertSame('unknown', $metrics['gnss_satellites']['status']);
        $this->assertSame('unknown', $metrics['gnss_satellites_fix']['status']);
        $this->assertStringContainsString('GNSS-ontvanger', $metrics['gnss_satellites']['explanation']);
        $this->assertSame(0.0, $metrics['precipitation_outlook']['precipitation_outlook']['radar_peak_mm_h']);
        $this->assertNull($metrics['precipitation_outlook']['precipitation_outlook']['third_hour_probability_pct']);
        $this->assertFalse($metrics['thunderstorm_forecast']['thunderstorm_outlook']['expected']);

        Http::assertSentCount(2);
        $this->assertSame(1, $this->weatherForecasts->calls);
    }

    public function test_low_cloud_card_fails_closed_when_model_cloud_base_is_missing(): void
    {
        $this->setForecastTestNow();
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'cloud_base_m' => null,
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00Z', 'estimated_kp' => 2.0],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $metric = collect($forecast['metrics'])->firstWhere('key', 'low_cloud_cover_pct');

        $this->assertSame('unknown', $metric['status']);
        $this->assertSame([
            'status' => 'unknown',
            'base_height_m' => null,
            'height_reference' => 'model_unspecified',
            'aggregation' => 'single_grid_point',
            'sample_count' => 0,
            'expected_sample_count' => 1,
            'model_run_at' => '2026-07-20T09:00:00+00:00',
            'valid_at' => '2026-07-20T12:00:00+00:00',
            'attribution' => 'DMI_HARMONIE',
        ], $metric['cloud_base_forecast']);
    }

    public function test_missing_cloud_base_never_hides_a_known_red_low_cloud_hazard(): void
    {
        $this->setForecastTestNow();
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'cloud_cover_low_pct' => 100.0,
            'cloud_base_m' => null,
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00Z', 'estimated_kp' => 2.0],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $metric = collect($forecast['metrics'])->firstWhere('key', 'low_cloud_cover_pct');

        $this->assertSame('red', $metric['status']);
        $this->assertSame('red', $forecast['overall_status']);
        $this->assertStringContainsString(
            'De modelwolkenbasis is niet volledig en actueel beschikbaar.',
            $metric['explanation'],
        );
    }

    public function test_dmi_precipitation_outlook_remains_usable_without_fabricating_a_probability(): void
    {
        $this->setForecastTestNow();
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00', 'kp_index' => 3, 'estimated_kp' => 3.0, 'kp' => '3'],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $metric = collect($forecast['metrics'])->firstWhere('key', 'precipitation_outlook');
        $outlook = $metric['precipitation_outlook'];

        $this->assertSame(0.0, $metric['value']);
        $this->assertSame('green', $metric['status']);
        $this->assertIsArray($outlook);
        $this->assertSame('green', $outlook['radar_status']);
        $this->assertSame('unknown', $outlook['third_hour_probability_status']);
        $this->assertSame('2026-07-20T15:00:00+00:00', $outlook['radar_until']);
        $this->assertNull($outlook['third_hour_probability_pct']);
        $this->assertNull($outlook['third_hour_from']);
        $this->assertNull($outlook['forecast_until']);
        $this->assertSame('DMI', $outlook['attribution']);
        $this->assertStringContainsString('geen verzonnen neerslagkans', $metric['explanation']);
    }

    public function test_dwd_fallback_keeps_available_uav_metrics_live_and_missing_height_data_unknown(): void
    {
        $this->setForecastTestNow();
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'provider_identifier' => 'dwd_mosmix_bright_sky',
            'weather_code' => 2,
            'temperature_c' => 18.0,
            'dew_point_c' => 11.0,
            'dew_point_spread_c' => 7.0,
            'wind_speed_10m_kmh' => 18.0,
            'wind_speed_100m_kmh' => null,
            'wind_speed_150m_kmh' => null,
            'wind_speed_kmh' => 18.0,
            'wind_reference_height_agl_m' => 10,
            'wind_gust_kmh' => 25.0,
            'wind_direction_degrees' => 240.0,
            'precipitation_probability_pct' => 10.0,
            'precipitation_mm' => 0.0,
            'precipitation_rate_mm_h' => 0.0,
            'cloud_cover_pct' => 55.0,
            'cloud_cover_low_pct' => null,
            'cloud_cover_mid_pct' => null,
            'cloud_cover_high_pct' => null,
            'cloud_base_m' => null,
            'cloud_base_expected_sample_count' => 0,
            'cloud_base_aggregation' => null,
            'forecast_precipitation_peak_mm_h' => 0.2,
            'forecast_precipitation_first_at' => '2026-07-20T14:00:00+00:00',
            'forecast_precipitation_third_hour_probability_pct' => 5.0,
            'forecast_precipitation_third_hour_from' => '2026-07-20T15:00:00+00:00',
            'forecast_precipitation_until' => '2026-07-20T16:00:00+00:00',
            'thunderstorm_expected' => false,
            'thunderstorm_probability_pct' => 0.0,
            'thunderstorm_first_expected_at' => null,
            'thunderstorm_forecast_until' => '2026-07-20T16:00:00+00:00',
            'model_run_at' => null,
            'valid_at' => '2026-07-20T13:00:00+00:00',
            'measured_at' => '2026-07-20T13:00:00+00:00',
            'source' => [
                'name' => 'DWD MOSMIX via Bright Sky',
                'url' => 'https://brightsky.dev/',
                'attribution' => 'Deutscher Wetterdienst (DWD), via Bright Sky; bewerkt door DIS',
                'processed_by' => 'DIS',
            ],
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00Z', 'estimated_kp' => 2.0],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $metrics = collect($forecast['metrics'])->keyBy('key');
        $wind = $metrics['wind_speed_kmh'];
        $precipitation = $metrics['precipitation_outlook']['precipitation_outlook'];
        $thunder = $metrics['thunderstorm_forecast']['thunderstorm_outlook'];
        $lowCloud = $metrics['low_cloud_cover_pct'];

        $this->assertTrue($forecast['aggregation']['complete']);
        $this->assertTrue($forecast['aggregation']['fresh']);
        $this->assertSame('Gedeeltelijk bewolkt', $forecast['condition']['label']);
        $this->assertSame(18.0, $metrics['temperature_c']['value']);
        $this->assertSame(10, $wind['altitude_m']);
        $this->assertSame('10 m boven maaiveld', $wind['source_height_label']);
        $this->assertSame([['height_agl_m' => 10, 'speed_kmh' => 18.0]], $wind['height_samples_agl_m']);
        $this->assertSame(5.0, $precipitation['third_hour_probability_pct']);
        $this->assertSame('DWD_MOSMIX', $precipitation['attribution']);
        $this->assertSame('DWD_MOSMIX', $thunder['attribution']);
        $this->assertNull($lowCloud['value']);
        $this->assertSame('unknown', $lowCloud['status']);
        $this->assertNull($lowCloud['cloud_layers']);
        $this->assertSame([
            'status' => 'unknown',
            'base_height_m' => null,
            'height_reference' => 'model_unspecified',
            'aggregation' => null,
            'sample_count' => 0,
            'expected_sample_count' => 0,
            'model_run_at' => null,
            'valid_at' => null,
            'attribution' => 'DWD_MOSMIX',
        ], $lowCloud['cloud_base_forecast']);
        $this->assertSame('orange', $forecast['overall_status']);
        $this->assertStringContainsString('bovenwind', $forecast['scope_note']);
        $this->assertStringContainsString('ontbrekende hoogte-', mb_strtolower($forecast['disclaimer']));
    }

    public function test_overall_advice_uses_low_instead_of_total_cloud_cover(): void
    {
        $this->setForecastTestNow();
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'wind_speed_10m_kmh' => 10.0,
            'wind_speed_100m_kmh' => 15.0,
            'wind_speed_150m_kmh' => 20.0,
            'wind_speed_kmh' => 15.0,
            'wind_gust_kmh' => 20.0,
            'cloud_cover_pct' => 100.0,
            'cloud_cover_low_pct' => 20.0,
            'cloud_cover_mid_pct' => 100.0,
            'cloud_cover_high_pct' => 100.0,
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00Z', 'estimated_kp' => 3.0],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $metrics = collect($forecast['metrics'])->keyBy('key');

        $this->assertSame('red', $metrics['cloud_cover_pct']['status']);
        $this->assertSame('green', $metrics['low_cloud_cover_pct']['status']);
        // GNSS remains deliberately unknown. If total cloud cover still counted,
        // the overall result would be red instead of fail-closed unknown.
        $this->assertSame('unknown', $forecast['overall_status']);
    }

    public function test_thunderstorm_card_uses_the_next_three_hours_without_claiming_live_detection(): void
    {
        $this->setForecastTestNow();
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'thunderstorm_expected' => true,
            'thunderstorm_probability_pct' => 35.0,
            'thunderstorm_first_expected_at' => '2026-07-20T13:00:00+00:00',
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00Z', 'estimated_kp' => 2.0],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $metric = collect($forecast['metrics'])->firstWhere('key', 'thunderstorm_forecast');

        $this->assertSame('red', $metric['status']);
        $this->assertSame('red', $forecast['overall_status']);
        $this->assertTrue($metric['thunderstorm_outlook']['expected']);
        $this->assertSame('2026-07-20T13:00:00+00:00', $metric['thunderstorm_outlook']['first_expected_at']);
        $this->assertSame('DMI', $metric['thunderstorm_outlook']['attribution']);
        $this->assertStringContainsString('geen live bliksemdetectie', mb_strtolower($metric['explanation']));
    }

    public function test_thunderstorm_card_fails_closed_when_dmi_probability_timeline_is_missing(): void
    {
        $this->setForecastTestNow();
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'thunderstorm_expected' => null,
            'thunderstorm_probability_pct' => null,
            'thunderstorm_first_expected_at' => null,
            'thunderstorm_forecast_until' => null,
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00Z', 'estimated_kp' => 2.0],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $metric = collect($forecast['metrics'])->firstWhere('key', 'thunderstorm_forecast');

        $this->assertSame('unknown', $metric['status']);
        $this->assertNull($metric['thunderstorm_outlook']);
    }

    public function test_thunderstorm_card_fails_closed_when_dmi_reading_is_stale(): void
    {
        $this->setForecastTestNow();
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'stale' => true,
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00Z', 'estimated_kp' => 2.0],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $metric = collect($forecast['metrics'])->firstWhere('key', 'thunderstorm_forecast');

        $this->assertSame('unknown', $metric['status']);
        $this->assertTrue($metric['stale']);
        $this->assertIsArray($metric['thunderstorm_outlook']);
    }

    public function test_uav_netherlands_uses_one_complete_twelve_province_dmi_reading(): void
    {
        $this->setForecastTestNow();
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'temperature_c' => 15.5,
            'dew_point_c' => 10.5,
            'dew_point_spread_c' => 5.0,
            'wind_speed_10m_kmh' => 15.5,
            'wind_speed_100m_kmh' => 25.5,
            'wind_speed_150m_kmh' => 36.5,
            'wind_speed_kmh' => 25.5,
            'cloud_cover_pct' => 31.0,
            'cloud_cover_low_pct' => 16.0,
            'cloud_cover_mid_pct' => 26.0,
            'cloud_cover_high_pct' => 36.0,
            'cloud_base_m' => 640.0,
            'visibility_m' => 12000.0,
            'sunrise_earliest' => '2026-07-20T04:00:00+00:00',
            'sunrise_latest' => '2026-07-20T04:11:00+00:00',
        ];

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
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
        $this->assertSame(25.5, $metrics['wind_speed_kmh']['value']);
        $this->assertSame(25.5, $metrics['wind_speed_kmh']['height_samples_agl_m'][1]['speed_kmh']);
        $this->assertSame(100, $metrics['wind_speed_kmh']['max_non_red_wind_height_agl_m']);
        $this->assertSame(31.0, $metrics['cloud_cover_pct']['value']);
        $this->assertSame(16.0, $metrics['low_cloud_cover_pct']['value']);
        $this->assertSame([
            'low_pct' => 16.0,
            'mid_pct' => 26.0,
            'high_pct' => 36.0,
            'total_pct' => 31.0,
        ], $metrics['low_cloud_cover_pct']['cloud_layers']);
        $this->assertSame('12.00', $metrics['visibility_m']['display_value']);
        $this->assertSame('km', $metrics['visibility_m']['display_unit']);
        $this->assertNull($forecast['condition']['code']);
        $this->assertSame('2026-07-20T04:00:00+00:00', $forecast['daylight']['sunrise_earliest']);
        $this->assertSame('2026-07-20T04:11:00+00:00', $forecast['daylight']['sunrise_latest']);
        $this->assertSame('DMI HARMONIE DINI (12 provincies)', $metrics['wind_speed_kmh']['source']['name']);
        $this->assertStringContainsString('exact alle 12', $forecast['scope_note']);

        $this->assertSame(0, $this->sentCountStartingWith('https://nominatim.openstreetmap.org/search'));
        $this->assertSame(1, $this->weatherForecasts->calls);
    }

    public function test_dmi_utc_times_remain_unambiguous_in_the_api_contract(): void
    {
        $this->setForecastTestNow();
        $this->weatherForecasts->reading = [
            ...$this->weatherForecasts->reading,
            'sunrise_earliest' => '2026-07-20T03:45:00+00:00',
            'sunrise_latest' => '2026-07-20T03:45:00+00:00',
            'sunset_earliest' => '2026-07-20T19:30:00+00:00',
            'sunset_latest' => '2026-07-20T19:30:00+00:00',
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00Z', 'estimated_kp' => 2.0],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $temperature = collect($forecast['metrics'])->firstWhere('key', 'temperature_c');

        $this->assertSame('2026-07-20T12:00:00+00:00', $temperature['measured_at']);
        $this->assertSame('2026-07-20T03:45:00+00:00', $forecast['daylight']['sunrise_earliest']);
        $this->assertSame('2026-07-20T19:30:00+00:00', $forecast['daylight']['sunset_latest']);
        $this->assertSame('2026-07-20T12:15:00+00:00', $forecast['generated_at']);
        $this->assertFalse($temperature['stale']);
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
        $this->assertSame(1, $this->weatherForecasts->calls);
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
        $calls = ['kp_current' => 0, 'kp_fallback' => 0];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$calls) {
            if (str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search')) {
                return Http::response([['lat' => '52.0907', 'lon' => '5.1214']]);
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
        $this->assertSame('2026-07-20T12:15:00+00:00', $first['generated_at']);
        $this->assertSame(['kp_current' => 1, 'kp_fallback' => 1], $calls);
        $this->assertSame(1, $this->weatherForecasts->calls);

        CarbonImmutable::setTestNow('2026-07-20T12:29:59Z');
        $cached = $service->pages(['pages' => [$this->addressPage()]])['forecast-utrecht'];
        $this->assertSame($first['generated_at'], $cached['generated_at']);
        $this->assertSame(['kp_current' => 1, 'kp_fallback' => 1], $calls);
        $this->assertSame(2, $this->weatherForecasts->calls);

        CarbonImmutable::setTestNow('2026-07-20T12:30:01Z');
        $refreshed = $service->pages(['pages' => [$this->addressPage()]])['forecast-utrecht'];
        $this->assertSame('2026-07-20T12:30:01+00:00', $refreshed['generated_at']);
        $this->assertSame(['kp_current' => 2, 'kp_fallback' => 2], $calls);
        $this->assertSame(3, $this->weatherForecasts->calls);
    }

    public function test_kp_current_feed_skips_invalid_estimate_and_uses_valid_index_from_same_row(): void
    {
        $this->setForecastTestNow();
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([['lat' => '52.0907', 'lon' => '5.1214']]),
            'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json' => Http::response([
                ['time_tag' => '2026-07-20T12:10:00Z', 'estimated_kp' => -1, 'kp_index' => 4],
            ]),
        ]);

        $forecast = app(WallboardForecastService::class)->pages([
            'pages' => [$this->addressPage()],
        ])['forecast-utrecht'];
        $kp = collect($forecast['metrics'])->firstWhere('key', 'kp_index');

        $this->assertSame(4.0, $kp['value']);
        $this->assertSame('NOAA SWPC Kp (1 minuut)', $kp['source']['name']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json');
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
            'visible_blocks' => WallboardConfiguration::DEFAULT_FORECAST_VISIBLE_BLOCKS,
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

        $tooMany = $this->netherlandsPage();
        $tooMany['options']['visible_blocks'] = WallboardConfiguration::FORECAST_VISIBLE_BLOCKS;
        $this->assertConfigurationError($tooMany, 'configuration.pages.0.options.visible_blocks');
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
                WallboardConfiguration::DEFAULT_FORECAST_VISIBLE_BLOCKS,
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
                'visible_blocks' => WallboardConfiguration::DEFAULT_FORECAST_VISIBLE_BLOCKS,
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
                'visible_blocks' => WallboardConfiguration::DEFAULT_FORECAST_VISIBLE_BLOCKS,
            ],
        ];
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
            'dis.wallboards.uav_forecast.knmi_edr_api_key' => null,
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

final class StubUavWeatherForecastProvider implements UavWeatherForecastProvider
{
    /** @var array<string, mixed> */
    public array $reading = [
        'weather_code' => null,
        'temperature_c' => 18.0,
        'dew_point_c' => 12.0,
        'dew_point_spread_c' => 6.0,
        'wind_speed_10m_kmh' => 10.0,
        'wind_speed_100m_kmh' => 25.0,
        'wind_speed_150m_kmh' => 35.0,
        'wind_speed_kmh' => 25.0,
        'wind_gust_kmh' => 30.0,
        'wind_direction_degrees' => 90.0,
        'precipitation_probability_pct' => null,
        'precipitation_mm' => 0.0,
        'precipitation_rate_mm_h' => 0.0,
        'visibility_m' => 10000.0,
        'cloud_cover_pct' => 40.0,
        'cloud_cover_low_pct' => 20.0,
        'cloud_cover_mid_pct' => 60.0,
        'cloud_cover_high_pct' => 80.0,
        'cloud_base_m' => 850.0,
        'forecast_precipitation_peak_mm_h' => 0.0,
        'forecast_precipitation_first_at' => null,
        'forecast_precipitation_until' => '2026-07-20T15:00:00+00:00',
        'thunderstorm_expected' => false,
        'thunderstorm_probability_pct' => 0.0,
        'thunderstorm_first_expected_at' => null,
        'thunderstorm_forecast_until' => '2026-07-20T15:00:00+00:00',
        'sunrise_earliest' => '2026-07-20T04:30:00+00:00',
        'sunrise_latest' => '2026-07-20T04:30:00+00:00',
        'sunset_earliest' => '2026-07-20T20:45:00+00:00',
        'sunset_latest' => '2026-07-20T20:45:00+00:00',
    ];

    public int $calls = 0;

    public function forResolution(array $resolution): array
    {
        $this->calls++;
        if (($resolution['complete'] ?? false) !== true) {
            return [
                'complete' => false,
                'stale' => false,
                'source' => ['name' => 'DMI HARMONIE DINI', 'url' => null],
                'sample_count' => 0,
                'expected_sample_count' => (int) ($resolution['expected_locations'] ?? 0),
                'availability_note' => 'Testlocatie onvolledig.',
            ];
        }

        $sampleCount = (int) ($resolution['expected_locations'] ?? 1);

        return [
            ...$this->reading,
            'cloud_base_sample_count' => $this->reading['cloud_base_m'] === null ? 0 : $sampleCount,
            'cloud_base_expected_sample_count' => array_key_exists('cloud_base_expected_sample_count', $this->reading)
                ? $this->reading['cloud_base_expected_sample_count']
                : $sampleCount,
            'cloud_base_complete' => array_key_exists('cloud_base_complete', $this->reading)
                ? (bool) $this->reading['cloud_base_complete']
                : $this->reading['cloud_base_m'] !== null
                    && ! (bool) ($this->reading['stale'] ?? false),
            'cloud_base_aggregation' => array_key_exists('cloud_base_aggregation', $this->reading)
                ? $this->reading['cloud_base_aggregation']
                : ($sampleCount === 12 ? 'minimum_of_province_samples' : 'single_grid_point'),
            'sample_count' => $sampleCount,
            'expected_sample_count' => $sampleCount,
            'complete' => (bool) ($this->reading['complete'] ?? true),
            'stale' => (bool) ($this->reading['stale'] ?? false),
            'model_run_at' => array_key_exists('model_run_at', $this->reading)
                ? $this->reading['model_run_at']
                : '2026-07-20T09:00:00+00:00',
            'valid_at' => $this->reading['valid_at'] ?? '2026-07-20T12:00:00+00:00',
            'measured_at' => $this->reading['measured_at'] ?? '2026-07-20T12:00:00+00:00',
            'refreshed_at' => $this->reading['refreshed_at'] ?? '2026-07-20T12:14:00+00:00',
            'source' => is_array($this->reading['source'] ?? null)
                ? $this->reading['source']
                : [
                    'name' => $sampleCount === 12 ? 'DMI HARMONIE DINI (12 provincies)' : 'DMI HARMONIE DINI',
                    'url' => 'https://www.dmi.dk/friedata/dokumentation/forecast-data-edr-api',
                ],
        ];
    }
}
