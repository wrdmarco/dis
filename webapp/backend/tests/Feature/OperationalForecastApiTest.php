<?php

namespace Tests\Feature;

use App\Contracts\KnmiCloudForecastProvider;
use App\Contracts\KnmiPrecipitationOutlookProvider;
use App\Models\User;
use App\Services\WallboardForecastService;
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

    private OperationalWeatherCloudProviderStub $cloud;

    private OperationalWeatherPrecipitationProviderStub $precipitation;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-21T10:30:00Z');
        Cache::flush();
        $this->cloud = new OperationalWeatherCloudProviderStub;
        $this->precipitation = new OperationalWeatherPrecipitationProviderStub;
        $this->app->instance(KnmiCloudForecastProvider::class, $this->cloud);
        $this->app->instance(KnmiPrecipitationOutlookProvider::class, $this->precipitation);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_forecast_endpoints_require_authentication_and_completed_two_factor_but_no_permission(): void
    {
        $this->getJson('/api/operational-weather')->assertUnauthorized();
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

        Http::preventStrayRequests();
        $this->asWebClient($user)
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'current');
        Http::assertNothingSent();
    }

    public function test_operational_weather_defaults_to_a_complete_national_local_knmi_contract(): void
    {
        Http::preventStrayRequests();

        $response = $this->asWebClient($this->user('national-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.location.mode', 'netherlands')
            ->assertJsonPath('data.location.label', 'UAV Nederland')
            ->assertJsonPath('data.aggregation.type', 'province_average')
            ->assertJsonPath('data.aggregation.sample_count', 12)
            ->assertJsonPath('data.aggregation.expected_sample_count', 12)
            ->assertJsonPath('data.aggregation.complete', true)
            ->assertJsonPath('data.aggregation.fresh', true)
            ->assertJsonPath('data.generated_at', '2026-07-21T10:30:00+00:00')
            ->assertJsonPath('data.data_status', 'current')
            ->assertJsonPath('data.cloud.cloud_cover_pct', 70)
            ->assertJsonPath('data.cloud.cloud_cover_low_pct', 25)
            ->assertJsonPath('data.cloud.cloud_base_m', 820)
            ->assertJsonPath('data.cloud.source.name', 'KNMI HARMONIE P1 (12 provincies)')
            ->assertJsonPath('data.precipitation.radar_peak_mm_h', 0.4)
            ->assertJsonPath('data.precipitation.third_hour_probability_pct', 35)
            ->assertJsonPath('data.precipitation.source.name', 'KNMI lokale radar + ensemblekans (12 locaties)')
            ->assertJsonStructure(['data' => [
                'location' => ['mode', 'label', 'latitude', 'longitude'],
                'aggregation' => ['type', 'sample_count', 'expected_sample_count', 'complete', 'fresh'],
                'generated_at',
                'data_status',
                'cloud' => [
                    'complete', 'stale', 'cloud_cover_pct', 'cloud_cover_low_pct',
                    'cloud_cover_mid_pct', 'cloud_cover_high_pct', 'cloud_base_m',
                    'model_run_at', 'valid_at', 'measured_at', 'refreshed_at',
                    'sample_count', 'expected_sample_count', 'source', 'availability_note',
                ],
                'precipitation' => [
                    'complete', 'stale', 'radar_peak_mm_h', 'radar_first_precipitation_at',
                    'radar_until', 'third_hour_probability_pct', 'third_hour_from',
                    'forecast_until', 'reference_time', 'measured_at', 'refreshed_at',
                    'sample_count', 'expected_sample_count', 'source', 'availability_note',
                ],
                'scope_note',
                'disclaimer',
            ]]);

        $this->assertStringNotContainsString('path', $response->getContent());
        $this->assertStringNotContainsString('sha256', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_address_is_resolved_server_side_before_local_knmi_providers_are_called(): void
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

        $this->assertSame(1, $this->cloud->lastResolution['expected_locations']);
        $this->assertSame(52.0907, $this->cloud->lastResolution['locations'][0]['latitude']);
        $this->assertSame($this->cloud->lastResolution, $this->precipitation->lastResolution);
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

    public function test_missing_or_stale_local_sources_never_report_current(): void
    {
        Http::preventStrayRequests();
        $client = $this->asWebClient($this->user('fail-closed-weather@example.test'));
        $this->precipitation->overrides = [
            'complete' => false,
            'radar_peak_mm_h' => null,
            'availability_note' => 'Geen complete lokale neerslagsnapshot.',
        ];

        $client->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'partial')
            ->assertJsonPath('data.aggregation.sample_count', 0)
            ->assertJsonPath('data.aggregation.complete', false)
            ->assertJsonPath('data.aggregation.fresh', false)
            ->assertJsonPath('data.precipitation.complete', false);

        $this->cloud->overrides = [
            'stale' => true,
            'availability_note' => 'De lokale KNMI-modelrun is verouderd.',
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
        $this->cloud->overrides = [
            'sample_count' => 1,
            'expected_sample_count' => 1,
        ];
        $this->precipitation->overrides = [
            'sample_count' => 1,
            'expected_sample_count' => 1,
            'radar_sample_count' => 1,
            'third_hour_sample_count' => 1,
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
        $this->cloud->overrides = [
            'model_run_at' => '2026-07-19T09:00:00+00:00',
            'stale' => false,
        ];
        $this->precipitation->overrides = [
            'reference_time' => '2026-07-20T10:30:00+00:00',
            'measured_at' => '2026-07-20T10:30:00+00:00',
            'radar_first_precipitation_at' => null,
            'radar_until' => '2026-07-20T12:30:00+00:00',
            'third_hour_from' => '2026-07-20T12:30:00+00:00',
            'forecast_until' => '2026-07-20T13:30:00+00:00',
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
        $this->cloud->overrides = [
            'refreshed_at' => '2026-07-21T08:59:00+00:00',
            'stale' => false,
        ];
        $this->precipitation->overrides = [
            'refreshed_at' => '2026-07-21T10:29:00+00:00',
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
        $this->precipitation->overrides = [
            'refreshed_at' => '2026-07-21T11:00:00+00:00',
            'stale' => false,
        ];

        $this->asWebClient($this->user('future-timestamp-weather@example.test'))
            ->getJson('/api/operational-weather')
            ->assertOk()
            ->assertJsonPath('data.generated_at', '2026-07-21T10:28:00+00:00')
            ->assertJsonPath('data.data_status', 'partial')
            ->assertJsonPath('data.cloud.complete', true)
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

    private function asWebClient(User $user): static
    {
        $token = $user->createToken(
            'Operational forecast web client',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}

final class OperationalWeatherCloudProviderStub implements KnmiCloudForecastProvider
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
                'source' => ['name' => 'KNMI HARMONIE P1', 'url' => 'https://dataplatform.knmi.nl/'],
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
            'model_run_at' => '2026-07-21T09:00:00+00:00',
            'valid_at' => '2026-07-21T10:00:00+00:00',
            'measured_at' => '2026-07-21T10:00:00+00:00',
            'refreshed_at' => '2026-07-21T10:28:00+00:00',
            'sample_count' => $sampleCount,
            'expected_sample_count' => $sampleCount,
            'source' => [
                'name' => $sampleCount === 12 ? 'KNMI HARMONIE P1 (12 provincies)' : 'KNMI HARMONIE P1',
                'url' => 'https://dataplatform.knmi.nl/',
            ],
            'availability_note' => null,
            ...$this->overrides,
        ];
    }
}

final class OperationalWeatherPrecipitationProviderStub implements KnmiPrecipitationOutlookProvider
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
                'source' => ['name' => 'KNMI lokale radar + ensemblekans', 'url' => 'https://dataplatform.knmi.nl/'],
                'availability_note' => 'Testlocatie onvolledig.',
            ];
        }

        $sampleCount = (int) $resolution['expected_locations'];

        return [
            'complete' => true,
            'stale' => false,
            'radar_peak_mm_h' => 0.4,
            'radar_first_precipitation_at' => '2026-07-21T10:55:00+00:00',
            'radar_until' => '2026-07-21T12:30:00+00:00',
            'third_hour_probability_pct' => 35.0,
            'third_hour_from' => '2026-07-21T12:30:00+00:00',
            'forecast_until' => '2026-07-21T13:30:00+00:00',
            'reference_time' => '2026-07-21T10:30:00+00:00',
            'measured_at' => '2026-07-21T10:30:00+00:00',
            'refreshed_at' => '2026-07-21T10:30:00+00:00',
            'radar_sample_count' => 25 * $sampleCount,
            'third_hour_sample_count' => 13 * $sampleCount,
            'sample_count' => $sampleCount,
            'expected_sample_count' => $sampleCount,
            'source' => [
                'name' => $sampleCount === 12
                    ? 'KNMI lokale radar + ensemblekans (12 locaties)'
                    : 'KNMI lokale radar + ensemblekans',
                'url' => 'https://dataplatform.knmi.nl/',
            ],
            'availability_note' => null,
            ...$this->overrides,
        ];
    }
}
