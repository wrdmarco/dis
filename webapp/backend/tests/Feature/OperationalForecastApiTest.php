<?php

namespace Tests\Feature;

use App\Contracts\GnssForecastProvider;
use App\Contracts\OperationalRadarProvider;
use App\Contracts\UavWeatherForecastProvider;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\WallboardForecastService;
use App\Support\OperationalRadarContent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OperationalForecastApiTest extends TestCase
{
    use RefreshDatabase;

    private OperationalWeatherForecastProviderStub $weather;

    private OperationalRadarProviderStub $radar;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-21T10:30:00Z');
        Cache::flush();
        $this->weather = new OperationalWeatherForecastProviderStub;
        $this->radar = new OperationalRadarProviderStub;
        $this->app->instance(UavWeatherForecastProvider::class, $this->weather);
        $this->app->instance(GnssForecastProvider::class, new OperationalGnssForecastProviderStub);
        $this->app->instance(OperationalRadarProvider::class, $this->radar);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_forecast_endpoints_require_authentication_completed_two_factor_and_explicit_permissions(): void
    {
        $this->getJson('/api/operational-weather')->assertUnauthorized();
        $this->getJson('/api/operational-weather/radar')->assertUnauthorized();
        $this->getJson('/api/uav-forecast')->assertUnauthorized();

        $user = $this->user('operational-forecast@example.test');
        $pending = $user->createToken(
            'Operational forecast pending 2FA',
            ['2fa:pending', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($pending)
            ->getJson('/api/operational-weather')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $this->asWebClient($user, grantForecastPermissions: false)
            ->getJson('/api/operational-weather')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->asWebClient($user, grantForecastPermissions: false)
            ->getJson('/api/operational-weather/radar')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->asWebClient($user, grantForecastPermissions: false)
            ->getJson('/api/uav-forecast')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->grant($user, ['operational-weather.view']);
        Http::preventStrayRequests();
        $this->asWebClient($user, grantForecastPermissions: false)
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'current');
        $this->asWebClient($user, grantForecastPermissions: false)
            ->getJson('/api/uav-forecast')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        Http::assertNothingSent();
    }

    public function test_radar_metadata_fast_path_does_not_load_the_dmi_forecast(): void
    {
        Http::preventStrayRequests();

        $this->asWebClient($this->user('radar-fast-path@example.test'))
            ->getJson('/api/operational-weather/radar')
            ->assertOk()
            ->assertJsonPath('data.location.mode', 'netherlands')
            ->assertJsonPath('data.location.label', 'Nederland')
            ->assertJsonPath('data.generated_at', '2026-07-21T10:30:00+00:00')
            ->assertJsonPath('data.radar.precipitation.status', 'unavailable')
            ->assertJsonPath('data.radar.lightning.status', 'unavailable')
            ->assertJsonStructure(['data' => [
                'location' => ['mode', 'label', 'latitude', 'longitude'],
                'generated_at',
                'radar' => ['precipitation', 'lightning'],
            ]]);

        $this->assertSame([], $this->weather->lastResolution);
        Http::assertNothingSent();
    }

    public function test_operational_weather_defaults_to_a_complete_national_live_dmi_contract(): void
    {
        Http::preventStrayRequests();

        $response = $this->asWebClient($this->user('national-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.location.mode', 'netherlands')
            ->assertJsonPath('data.location.label', 'Nederland')
            ->assertJsonPath('data.aggregation.type', 'province_average')
            ->assertJsonPath('data.aggregation.sample_count', 12)
            ->assertJsonPath('data.aggregation.expected_sample_count', 12)
            ->assertJsonPath('data.aggregation.complete', true)
            ->assertJsonPath('data.aggregation.fresh', true)
            ->assertJsonPath('data.generated_at', '2026-07-21T10:28:00+00:00')
            ->assertJsonPath('data.data_status', 'current')
            ->assertJsonPath('data.cloud.cloud_cover_pct', 70)
            ->assertJsonPath('data.cloud.cloud_cover_low_pct', 25)
            ->assertJsonPath('data.cloud.cloud_base_m', 820)
            ->assertJsonPath('data.cloud.cloud_base_complete', true)
            ->assertJsonPath('data.cloud.cloud_base_sample_count', 12)
            ->assertJsonPath('data.cloud.cloud_base_expected_sample_count', 12)
            ->assertJsonPath('data.cloud.source.name', 'DMI HARMONIE DINI (12 provincies)')
            ->assertJsonPath('data.cloud.source.license', 'CC BY 4.0')
            ->assertJsonPath(
                'data.cloud.source.license_url',
                'https://www.dmi.dk/friedata/dokumentation/terms-of-use',
            )
            ->assertJsonPath('data.cloud.source.attribution', 'Contains modified DMI data')
            ->assertJsonPath('data.cloud.source.modified', true)
            ->assertJsonPath('data.cloud.source.processed_by', 'DIS')
            ->assertJsonPath('data.precipitation.radar_peak_mm_h', 0.4)
            ->assertJsonPath('data.precipitation.probability_complete', false)
            ->assertJsonPath('data.precipitation.third_hour_probability_pct', null)
            ->assertJsonPath('data.precipitation.source.name', 'DMI HARMONIE DINI (12 provincies)')
            ->assertJsonPath('data.radar.precipitation.status', 'unavailable')
            ->assertJsonPath('data.radar.lightning.status', 'unavailable')
            ->assertJsonStructure(['data' => [
                'location' => ['mode', 'label', 'latitude', 'longitude'],
                'aggregation' => ['type', 'sample_count', 'expected_sample_count', 'complete', 'fresh'],
                'generated_at',
                'data_status',
                'cloud' => [
                    'complete', 'stale', 'cloud_cover_pct', 'cloud_cover_low_pct',
                    'cloud_cover_mid_pct', 'cloud_cover_high_pct', 'cloud_base_m',
                    'cloud_base_complete', 'cloud_base_sample_count', 'cloud_base_expected_sample_count',
                    'model_run_at', 'valid_at', 'measured_at', 'refreshed_at',
                    'sample_count', 'expected_sample_count', 'source', 'availability_note',
                ],
                'precipitation' => [
                    'complete', 'probability_complete', 'stale', 'radar_peak_mm_h', 'radar_first_precipitation_at',
                    'radar_until', 'third_hour_probability_pct', 'third_hour_from',
                    'forecast_until', 'reference_time', 'measured_at', 'refreshed_at',
                    'sample_count', 'expected_sample_count', 'source', 'availability_note',
                ],
                'radar' => [
                    'precipitation' => [
                        'status', 'reference_time', 'observed_period_end',
                        'age_seconds', 'lag_seconds', 'refreshed_at', 'atlas_url',
                        'atlas_columns', 'atlas_rows', 'frame_width', 'frame_height',
                        'frames', 'source', 'availability_note',
                    ],
                    'lightning' => [
                        'status', 'reference_time', 'observed_period_end',
                        'age_seconds', 'lag_seconds', 'refreshed_at', 'atlas_url',
                        'atlas_columns', 'atlas_rows', 'frame_width', 'frame_height',
                        'frames', 'source', 'availability_note',
                    ],
                ],
                'scope_note',
                'disclaimer',
            ]]);

        $this->assertStringNotContainsString('path', $response->getContent());
        $this->assertStringNotContainsString('sha256', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_operational_weather_is_partial_when_one_national_cloud_base_sample_is_missing(): void
    {
        $this->weather->overrides = [
            'cloud_base_m' => null,
            'cloud_base_sample_count' => 11,
            'cloud_base_expected_sample_count' => 12,
            'cloud_base_complete' => false,
        ];
        Http::preventStrayRequests();

        $this->asWebClient($this->user('partial-cloud-base-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'partial')
            ->assertJsonPath('data.aggregation.complete', false)
            ->assertJsonPath('data.aggregation.fresh', false)
            ->assertJsonPath('data.cloud.complete', false)
            ->assertJsonPath('data.cloud.cloud_base_m', null)
            ->assertJsonPath('data.cloud.cloud_base_complete', false)
            ->assertJsonPath('data.cloud.cloud_base_sample_count', 11)
            ->assertJsonPath('data.cloud.cloud_base_expected_sample_count', 12)
            ->assertJsonPath('data.precipitation.complete', true);
    }

    public function test_operational_weather_rejects_a_claimed_complete_cloud_base_without_valid_height(): void
    {
        $this->weather->overrides = [
            'cloud_base_m' => 'invalid',
            'cloud_base_sample_count' => 12,
            'cloud_base_expected_sample_count' => 12,
            'cloud_base_complete' => true,
        ];
        Http::preventStrayRequests();

        $this->asWebClient($this->user('invalid-cloud-base-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'partial')
            ->assertJsonPath('data.cloud.complete', false)
            ->assertJsonPath('data.cloud.cloud_base_m', null)
            ->assertJsonPath('data.cloud.cloud_base_complete', false);
    }

    public function test_operational_weather_exposes_dwd_fallback_precipitation_and_keeps_unsupported_cloud_fields_partial(): void
    {
        $this->weather->overrides = [
            'provider_identifier' => 'dwd_mosmix_bright_sky',
            'cloud_cover_pct' => 55.0,
            'cloud_cover_low_pct' => null,
            'cloud_cover_mid_pct' => null,
            'cloud_cover_high_pct' => null,
            'cloud_base_m' => null,
            'cloud_base_sample_count' => 0,
            'cloud_base_expected_sample_count' => 0,
            'cloud_base_complete' => false,
            'forecast_precipitation_peak_mm_h' => 0.0,
            'forecast_precipitation_first_at' => null,
            'forecast_precipitation_third_hour_probability_pct' => 10.0,
            'forecast_precipitation_third_hour_from' => '2026-07-21T13:00:00+00:00',
            'forecast_precipitation_until' => '2026-07-21T14:00:00+00:00',
            'model_run_at' => null,
            'valid_at' => '2026-07-21T11:00:00+00:00',
            'measured_at' => '2026-07-21T11:00:00+00:00',
            'source' => [
                'name' => 'DWD MOSMIX via Bright Sky (12 provincies)',
                'url' => 'https://brightsky.dev/',
                'license' => 'CC BY 4.0',
                'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
                'attribution' => 'Weergegevens: Deutscher Wetterdienst (DWD); API: Bright Sky',
                'modified' => true,
                'processed_by' => 'DIS',
                'processing_note' => 'Alleen 10 m AGL-wind is beschikbaar.',
            ],
        ];
        Http::preventStrayRequests();

        $this->asWebClient($this->user('dwd-fallback-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'partial')
            ->assertJsonPath('data.aggregation.sample_count', 0)
            ->assertJsonPath('data.aggregation.complete', false)
            ->assertJsonPath('data.aggregation.fresh', false)
            ->assertJsonPath('data.cloud.complete', false)
            ->assertJsonPath('data.cloud.stale', false)
            ->assertJsonPath('data.cloud.cloud_cover_pct', 55)
            ->assertJsonPath('data.cloud.cloud_cover_low_pct', null)
            ->assertJsonPath('data.cloud.cloud_base_expected_sample_count', 0)
            ->assertJsonPath('data.cloud.model_run_at', null)
            ->assertJsonPath('data.cloud.source.name', 'DWD MOSMIX via Bright Sky (12 provincies)')
            ->assertJsonPath('data.cloud.source.processing_note', 'Alleen 10 m AGL-wind is beschikbaar.')
            ->assertJsonPath('data.precipitation.complete', true)
            ->assertJsonPath('data.precipitation.probability_complete', true)
            ->assertJsonPath('data.precipitation.third_hour_probability_pct', 10)
            ->assertJsonPath('data.precipitation.third_hour_from', '2026-07-21T13:00:00+00:00')
            ->assertJsonPath('data.precipitation.forecast_until', '2026-07-21T14:00:00+00:00')
            ->assertJsonPath('data.precipitation.source.name', 'DWD MOSMIX via Bright Sky (12 provincies)')
            ->assertJsonPath('data.scope_note', fn (string $note): bool => str_contains($note, 'DWD MOSMIX'))
            ->assertJsonPath('data.disclaimer', fn (string $note): bool => str_contains($note, 'Bright Sky'));

        Http::assertNothingSent();
    }

    public function test_operational_weather_keeps_dmi_model_precipitation_current_without_fabricating_probability(): void
    {
        Http::preventStrayRequests();

        $this->asWebClient($this->user('radar-only-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'current')
            ->assertJsonPath('data.aggregation.complete', true)
            ->assertJsonPath('data.aggregation.fresh', true)
            ->assertJsonPath('data.precipitation.complete', true)
            ->assertJsonPath('data.precipitation.probability_complete', false)
            ->assertJsonPath('data.precipitation.radar_peak_mm_h', 0.4)
            ->assertJsonPath('data.precipitation.radar_until', '2026-07-21T13:00:00+00:00')
            ->assertJsonPath('data.precipitation.third_hour_probability_pct', null)
            ->assertJsonPath('data.precipitation.third_hour_from', null)
            ->assertJsonPath('data.precipitation.forecast_until', null)
            ->assertJsonPath('data.precipitation.source.name', 'DMI HARMONIE DINI (12 provincies)');

        Http::assertNothingSent();
    }

    public function test_weather_exposes_same_origin_radar_metadata_and_atlas_is_immutable_and_conditional(): void
    {
        Http::preventStrayRequests();
        $snapshot = '20260721T103000Z-o-0123456789abcdef';
        $png = "\x89PNG\r\n\x1a\nradar-fixture";
        $sha256 = hash('sha256', $png);
        $frames = [];
        for ($index = 0; $index < 25; $index++) {
            $frames[] = [
                'index' => $index,
                'valid_at' => CarbonImmutable::parse('2026-07-21T10:30:00Z')
                    ->addMinutes($index * 5)
                    ->toIso8601String(),
                'lead_minutes' => $index * 5,
            ];
        }
        $this->radar->metadata = [
            'precipitation' => [
                ...$this->radar->metadata['precipitation'],
                'status' => 'available',
                'reference_time' => '2026-07-21T10:30:00+00:00',
                'atlas_url' => '/api/operational-weather/radar/precipitation/'.$snapshot.'.png',
                'frame_width' => 700,
                'frame_height' => 765,
                'frames' => $frames,
            ],
            'lightning' => $this->radar->metadata['lightning'],
        ];
        $this->radar->files['precipitation|'.$snapshot] = OperationalRadarContent::fromBody($png);
        $client = $this->asWebClient($this->user('radar-content@example.test'));

        $client->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.radar.precipitation.status', 'available')
            ->assertJsonPath(
                'data.radar.precipitation.atlas_url',
                '/api/operational-weather/radar/precipitation/'.$snapshot.'.png',
            );

        $url = '/api/operational-weather/radar/precipitation/'.$snapshot.'.png';
        $response = $client->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Length', (string) strlen($png))
            ->assertHeader('ETag', '"'.$sha256.'"')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($png, $response->getContent());

        $client->withHeader('If-None-Match', 'W/"'.$sha256.'"')
            ->get($url)
            ->assertNotModified()
            ->assertHeader('ETag', '"'.$sha256.'"')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
        Http::assertNothingSent();
    }

    public function test_radar_atlas_requires_authentication_completed_two_factor_and_valid_identifiers(): void
    {
        $snapshot = '20260721T103000Z-o-0123456789abcdef';
        $url = '/api/operational-weather/radar/precipitation/'.$snapshot.'.png';

        $this->get($url)->assertUnauthorized();

        $user = $this->user('radar-auth@example.test');
        $pending = $user->createToken(
            'Operational radar pending 2FA',
            ['2fa:pending', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($pending)
            ->get($url)
            ->assertForbidden();

        $this->asWebClient($user, grantForecastPermissions: false)
            ->get($url)
            ->assertForbidden();

        $this->grant($user, ['operational-weather.view']);
        $this->asWebClient($user, grantForecastPermissions: false)
            ->get('/api/operational-weather/radar/unknown/'.$snapshot.'.png')
            ->assertNotFound();
        $this->asWebClient($user, grantForecastPermissions: false)
            ->get('/api/operational-weather/radar/lightning/not-a-snapshot.png')
            ->assertNotFound();
        $this->asWebClient($user, grantForecastPermissions: false)
            ->get($url)
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_address_is_resolved_server_side_before_live_dmi_provider_is_called(): void
    {
        config()->set('dis.geocoding.enabled', true);
        config()->set('dis.geocoding.provider', 'nominatim');
        config()->set('dis.geocoding.nominatim_url', 'https://nominatim.openstreetmap.org/search');
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([
                ['lat' => '52.0907', 'lon' => '5.1214'],
            ]),
        ]);

        $this->asWebClient($this->user('address-weather@example.test'))
            ->getJson('/api/operational-weather?location_mode=address&location_label=Utrecht%2C%20Nederland')
            ->assertOk()
            ->assertJsonPath('data.location.mode', 'address')
            ->assertJsonPath('data.location.label', 'Utrecht, Nederland')
            ->assertJsonPath('data.location.latitude', 52.0907)
            ->assertJsonPath('data.location.longitude', 5.1214)
            ->assertJsonPath('data.aggregation.type', 'single_location')
            ->assertJsonPath('data.aggregation.sample_count', 1)
            ->assertJsonPath('data.data_status', 'current');

        $this->assertSame(1, $this->weather->lastResolution['expected_locations']);
        $this->assertSame(52.0907, $this->weather->lastResolution['locations'][0]['latitude']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search'));
    }

    public function test_location_query_rejects_invalid_modes_labels_and_client_coordinates(): void
    {
        $client = $this->asWebClient($this->user('validation-weather@example.test'));

        $client->getJson('/api/operational-weather?location_mode=province')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['location_mode']]]);
        $client->getJson('/api/operational-weather?location_mode=address')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['location_label']]]);
        $client->getJson('/api/uav-forecast?location_mode=netherlands&location_label=Utrecht')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['location_label']]]);
        $client->getJson('/api/operational-weather?latitude=52.1&longitude=5.1')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['latitude', 'longitude']]]);
    }

    public function test_missing_or_stale_live_dmi_values_never_report_current(): void
    {
        Http::preventStrayRequests();
        $client = $this->asWebClient($this->user('fail-closed-weather@example.test'));
        $this->weather->overrides = [
            'forecast_precipitation_peak_mm_h' => null,
            'availability_note' => 'Geen complete DMI-modelneerslag.',
        ];

        $client->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'partial')
            ->assertJsonPath('data.aggregation.sample_count', 0)
            ->assertJsonPath('data.aggregation.complete', false)
            ->assertJsonPath('data.aggregation.fresh', false)
            ->assertJsonPath('data.precipitation.complete', false);

        $this->weather->overrides = [
            'stale' => true,
            'availability_note' => 'De live DMI-modelrun is verouderd.',
        ];

        $client->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'unavailable')
            ->assertJsonPath('data.cloud.stale', true)
            ->assertJsonPath('data.aggregation.fresh', false);
        Http::assertNothingSent();
    }

    public function test_provider_counts_and_sample_windows_cannot_weaken_national_coverage(): void
    {
        Http::preventStrayRequests();
        $this->weather->overrides = [
            'sample_count' => 1,
            'expected_sample_count' => 1,
        ];

        $this->asWebClient($this->user('coverage-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'unavailable')
            ->assertJsonPath('data.aggregation.sample_count', 0)
            ->assertJsonPath('data.aggregation.expected_sample_count', 12)
            ->assertJsonPath('data.aggregation.complete', false)
            ->assertJsonPath('data.cloud.complete', false)
            ->assertJsonPath('data.cloud.expected_sample_count', 12)
            ->assertJsonPath('data.precipitation.complete', false)
            ->assertJsonPath('data.precipitation.expected_sample_count', 12);
        Http::assertNothingSent();
    }

    public function test_old_provider_timestamps_are_reclassified_as_stale_even_when_provider_flags_are_green(): void
    {
        Http::preventStrayRequests();
        $this->weather->overrides = [
            'model_run_at' => '2026-07-19T09:00:00+00:00',
            'valid_at' => '2026-07-20T10:00:00+00:00',
            'measured_at' => '2026-07-20T10:00:00+00:00',
            'forecast_precipitation_first_at' => null,
            'forecast_precipitation_until' => '2026-07-20T13:00:00+00:00',
            'stale' => false,
        ];

        $this->asWebClient($this->user('timestamp-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'unavailable')
            ->assertJsonPath('data.aggregation.fresh', false)
            ->assertJsonPath('data.cloud.complete', false)
            ->assertJsonPath('data.cloud.stale', true)
            ->assertJsonPath('data.precipitation.complete', false)
            ->assertJsonPath('data.precipitation.stale', true);
        Http::assertNothingSent();
    }

    public function test_refresh_timestamps_must_follow_their_source_times(): void
    {
        Http::preventStrayRequests();
        $this->weather->overrides = [
            'refreshed_at' => '2026-07-21T08:59:00+00:00',
            'stale' => false,
        ];

        $this->asWebClient($this->user('source-timestamp-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.generated_at', '2026-07-21T10:30:00+00:00')
            ->assertJsonPath('data.data_status', 'unavailable')
            ->assertJsonPath('data.cloud.complete', false)
            ->assertJsonPath('data.cloud.stale', true)
            ->assertJsonPath('data.precipitation.complete', false)
            ->assertJsonPath('data.precipitation.stale', true);
        Http::assertNothingSent();
    }

    public function test_rejected_future_refresh_timestamp_cannot_poison_generated_at(): void
    {
        Http::preventStrayRequests();
        $this->weather->overrides = [
            'refreshed_at' => '2026-07-21T11:00:00+00:00',
            'stale' => false,
        ];

        $this->asWebClient($this->user('future-timestamp-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.generated_at', '2026-07-21T10:30:00+00:00')
            ->assertJsonPath('data.data_status', 'unavailable')
            ->assertJsonPath('data.cloud.complete', false)
            ->assertJsonPath('data.cloud.stale', true)
            ->assertJsonPath('data.precipitation.complete', false)
            ->assertJsonPath('data.precipitation.stale', true);
        Http::assertNothingSent();
    }

    public function test_forecast_reads_have_a_dedicated_bounded_client_limit(): void
    {
        Http::preventStrayRequests();
        $client = $this->asWebClient($this->user('rate-limited-weather@example.test'));

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $client->getJson('/api/operational-weather')->assertOk();
        }

        $client->getJson('/api/operational-weather')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
        Http::assertNothingSent();
    }

    public function test_uav_endpoint_returns_the_exact_existing_composed_forecast_contract(): void
    {
        config()->set('dis.wallboards.uav_forecast.province_reference_points', []);
        Http::preventStrayRequests();
        Http::fake([
            'https://services.swpc.noaa.gov/*' => Http::response([], 503),
        ]);
        $options = ['location_mode' => 'netherlands'];
        $service = app(WallboardForecastService::class);
        $expected = $service->forecastForOptions($options);
        $fromPages = $service->pages(['pages' => [[
            'id' => 'same-forecast',
            'type' => 'uav_forecast',
            'options' => $options,
        ]]])['same-forecast'];

        $this->assertSame($expected, $fromPages);
        $this->asWebClient($this->user('uav-forecast@example.test'))
            ->getJson('/api/uav-forecast')
            ->assertOk()
            ->assertExactJson(['data' => $expected]);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Operational Forecast User',
            'first_name' => 'Operational',
            'last_name' => 'Forecast User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function asWebClient(User $user, bool $grantForecastPermissions = true): static
    {
        if ($grantForecastPermissions) {
            $this->grant($user, ['operational-weather.view', 'uav-forecast.view']);
        }

        $token = $user->createToken(
            'Operational forecast web client',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @param list<string> $permissionNames */
    private function grant(User $user, array $permissionNames): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'operational-forecast-test-'.$user->id],
            [
                'display_name' => 'Operational forecast test role',
                'can_use_operator_app' => false,
                'can_use_admin_app' => true,
            ],
        );

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'category' => 'weather_configuration',
                    'display_name' => $permissionName,
                    'description' => 'Operational forecast test permission',
                ],
            );
            $role->permissions()->syncWithoutDetaching([
                $permission->id => ['created_at' => now()],
            ]);
        }

        $user->roles()->syncWithoutDetaching([
            $role->id => ['created_at' => now()],
        ]);
    }
}

final class OperationalGnssForecastProviderStub implements GnssForecastProvider
{
    public function forResolution(array $resolution): array
    {
        return [
            'complete' => false,
            'stale' => false,
            'measured_at' => null,
            'location_count' => count((array) ($resolution['locations'] ?? [])),
            'elevation_mask_deg' => 10.0,
            'counts' => null,
            'pdop' => null,
            'ephemeris' => null,
            'source' => [
                'name' => 'BKG / International GNSS Service (IGS)',
                'url' => 'https://igs.bkg.bund.de/root_ftp/IGS/BRDC/',
                'attribution' => 'BKG / International GNSS Service (IGS)',
                'terms_url' => 'https://igs.org/wp-content/uploads/2020/09/IGS-Data-and-Product-Disclaimer-and-Terms-of-Use-200805.pdf',
            ],
            'availability_note' => 'GNSS-testbron niet compleet.',
        ];
    }
}

final class OperationalWeatherForecastProviderStub implements UavWeatherForecastProvider
{
    /** @var array<string, mixed> */
    public array $overrides = [];

    /** @var array<string, mixed> */
    public array $lastResolution = [];

    public function forResolution(array $resolution): array
    {
        $this->lastResolution = $resolution;
        if (($resolution['complete'] ?? false) !== true) {
            return [
                'complete' => false,
                'stale' => false,
                'sample_count' => 0,
                'expected_sample_count' => (int) ($resolution['expected_locations'] ?? 0),
                'source' => ['name' => 'DMI HARMONIE DINI', 'url' => null],
                'availability_note' => 'Testlocatie onvolledig.',
            ];
        }

        $sampleCount = (int) $resolution['expected_locations'];

        return [
            'complete' => true,
            'stale' => false,
            'cloud_cover_pct' => 70.0,
            'cloud_cover_low_pct' => 25.0,
            'cloud_cover_mid_pct' => 50.0,
            'cloud_cover_high_pct' => 65.0,
            'cloud_base_m' => 820.0,
            'cloud_base_sample_count' => $sampleCount,
            'cloud_base_expected_sample_count' => $sampleCount,
            'cloud_base_complete' => true,
            'forecast_precipitation_peak_mm_h' => 0.4,
            'forecast_precipitation_first_at' => '2026-07-21T10:55:00+00:00',
            'forecast_precipitation_until' => '2026-07-21T13:00:00+00:00',
            'model_run_at' => '2026-07-21T09:00:00+00:00',
            'valid_at' => '2026-07-21T10:00:00+00:00',
            'measured_at' => '2026-07-21T10:00:00+00:00',
            'refreshed_at' => '2026-07-21T10:28:00+00:00',
            'sample_count' => $sampleCount,
            'expected_sample_count' => $sampleCount,
            'source' => [
                'name' => $sampleCount === 12 ? 'DMI HARMONIE DINI (12 provincies)' : 'DMI HARMONIE DINI',
                'url' => 'https://www.dmi.dk/friedata/dokumentation/forecast-data-edr-api',
                'license' => 'CC BY 4.0',
                'license_url' => 'https://www.dmi.dk/friedata/dokumentation/terms-of-use',
                'attribution' => 'Contains modified DMI data',
                'modified' => true,
                'processed_by' => 'DIS',
            ],
            'availability_note' => null,
            ...$this->overrides,
        ];
    }
}

final class OperationalRadarProviderStub implements OperationalRadarProvider
{
    /** @var array<string, mixed> */
    public array $metadata = [
        'precipitation' => [
            'status' => 'unavailable',
            'reference_time' => null,
            'observed_period_end' => null,
            'age_seconds' => null,
            'lag_seconds' => null,
            'refreshed_at' => null,
            'atlas_url' => null,
            'atlas_columns' => 5,
            'atlas_rows' => 5,
            'frame_width' => 0,
            'frame_height' => 0,
            'frames' => [],
            'source' => [
                'name' => 'KNMI RTCOR + radar forecast 2.0',
                'url' => 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
                'license' => 'CC BY 4.0',
                'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
                'attribution' => 'KNMI nl_rdr_data_rtcor_5m en radar_forecast_2.0',
            ],
            'availability_note' => 'Geen actuele live KNMI-radarframes beschikbaar.',
        ],
        'lightning' => [
            'status' => 'unavailable',
            'reference_time' => null,
            'observed_period_end' => null,
            'age_seconds' => null,
            'lag_seconds' => null,
            'refreshed_at' => null,
            'atlas_url' => null,
            'atlas_columns' => 4,
            'atlas_rows' => 2,
            'frame_width' => 0,
            'frame_height' => 0,
            'frames' => [],
            'source' => [
                'name' => 'EUMETSAT MTG Lightning Imager',
                'url' => 'https://view.eumetsat.int/',
                'license' => 'CC BY 4.0',
                'license_url' => 'https://user.eumetsat.int/resources/user-guides/data-registration-and-licensing',
                'attribution' => 'Contains modified EUMETSAT Meteosat data 2026',
                'modified' => true,
                'processed_by' => 'DIS',
            ],
            'availability_note' => 'Geen actuele bliksemradar beschikbaar.',
        ],
    ];

    /** @var array<string, OperationalRadarContent> */
    public array $files = [];

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function file(string $kind, string $snapshotId): ?OperationalRadarContent
    {
        return $this->files[$kind.'|'.$snapshotId] ?? null;
    }
}
