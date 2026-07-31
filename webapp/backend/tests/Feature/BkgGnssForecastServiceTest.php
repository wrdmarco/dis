<?php

namespace Tests\Feature;

use App\Contracts\GnssForecastProvider;
use App\Services\BkgGnssForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BkgGnssForecastServiceTest extends TestCase
{
    private const NOW = '2026-07-31T12:12:30Z';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::NOW);
        Cache::flush();
        config([
            'dis.wallboards.uav_forecast.gnss_source_cache_seconds' => 900,
            'dis.wallboards.uav_forecast.gnss_last_good_cache_seconds' => 21600,
            'dis.wallboards.uav_forecast.gnss_calculation_cache_seconds' => 300,
            'dis.wallboards.uav_forecast.gnss_failure_cache_seconds' => 30,
            'dis.wallboards.uav_forecast.gnss_lock_wait_milliseconds' => 100,
            'dis.wallboards.uav_forecast.gnss_ephemeris_max_age_seconds' => 14400,
            'dis.wallboards.uav_forecast.gnss_ephemeris_future_tolerance_seconds' => 1800,
            'dis.wallboards.uav_forecast.gnss_utc_offset_seconds' => 18,
            'dis.wallboards.uav_forecast.gnss_elevation_mask_degrees' => 10,
            'dis.wallboards.uav_forecast.gnss_min_ephemerides_per_constellation' => 12,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_calculates_conservative_gps_galileo_counts_and_combined_pdop_in_memory(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            $this->currentUrl() => Http::response(
                $this->gzip($this->rinex()),
                200,
                ['Content-Type' => 'application/gzip'],
            ),
            '*' => Http::response('', 404),
        ]);
        $filesBefore = Storage::disk('local')->allFiles();

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertTrue($result['complete']);
        $this->assertFalse($result['stale']);
        $this->assertSame('2026-07-31T12:10:00+00:00', $result['measured_at']);
        $this->assertSame(2, $result['location_count']);
        $this->assertSame(10.0, $result['elevation_mask_deg']);
        $this->assertIsInt($result['counts']['visible']);
        $this->assertIsInt($result['counts']['usable']);
        $this->assertGreaterThan(0, $result['counts']['visible']);
        $this->assertGreaterThan(0, $result['counts']['usable']);
        $this->assertLessThanOrEqual($result['counts']['visible'], $result['counts']['usable']);
        $this->assertGreaterThan(0, $result['counts']['visible_by_constellation']['gps']);
        $this->assertGreaterThan(0, $result['counts']['visible_by_constellation']['galileo']);
        $this->assertGreaterThan(0, $result['counts']['usable_by_constellation']['gps']);
        $this->assertGreaterThan(0, $result['counts']['usable_by_constellation']['galileo']);
        $this->assertSame(
            $result['counts']['visible'],
            $result['counts']['visible_by_constellation']['gps']
                + $result['counts']['visible_by_constellation']['galileo'],
        );
        $this->assertSame(
            $result['counts']['usable'],
            $result['counts']['usable_by_constellation']['gps']
                + $result['counts']['usable_by_constellation']['galileo'],
        );
        $this->assertTrue($result['pdop']['complete']);
        $this->assertTrue($result['pdop']['geometry_sufficient']);
        $this->assertSame(2, $result['pdop']['sample_count']);
        $this->assertSame(2, $result['pdop']['value_sample_count']);
        $this->assertIsFloat($result['pdop']['value']);
        $this->assertGreaterThan(0, $result['pdop']['value']);
        $this->assertSame(24, $result['ephemeris']['gps']);
        $this->assertSame(24, $result['ephemeris']['galileo']);
        $this->assertSame(48, $result['ephemeris']['satellite_count']);
        $this->assertSame(618, $result['ephemeris']['maximum_age_seconds']);
        $this->assertSame($this->currentUrl(), $result['source']['url']);
        $this->assertSame([$this->currentUrl()], $result['source']['urls']);
        $this->assertSame(['2026-07-31'], $result['source']['file_dates']);
        $this->assertSame('International GNSS Service (IGS), hosted by BKG', $result['source']['attribution']);
        $this->assertStringContainsString('not receiver measurements', $result['provenance']);
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === $this->currentUrl()
                && $request->header('Accept-Encoding')[0] === 'identity'
                && str_contains($request->header('Accept')[0], 'application/gzip');
        });
        Http::assertSentCount(1);

        $cached = app(BkgGnssForecastService::class)->forResolution($this->resolution());
        $this->assertSame($result, $cached);
        Http::assertSentCount(1);
    }

    public function test_it_reuses_the_shared_source_cache_for_another_location_set(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            $this->currentUrl() => Http::response(
                $this->gzip($this->rinex()),
                200,
                ['Content-Type' => 'application/x-gzip'],
            ),
            '*' => Http::response('', 404),
        ]);
        $service = app(BkgGnssForecastService::class);

        $first = $service->forResolution($this->resolution([
            ['label' => 'Utrecht', 'latitude' => 52.0907, 'longitude' => 5.1214],
        ]));
        $second = $service->forResolution($this->resolution([
            ['label' => 'Groningen', 'latitude' => 53.2194, 'longitude' => 6.5665],
        ]));

        $this->assertTrue($first['complete']);
        $this->assertTrue($second['complete']);
        Http::assertSentCount(1);
    }

    public function test_download_uses_only_a_bounded_memory_sink_and_transfer_guards(): void
    {
        $guarded = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) use (&$guarded) {
            $sink = $options['sink'] ?? null;
            $metadata = is_resource($sink) ? stream_get_meta_data($sink) : [];
            $guarded = $request->url() === $this->currentUrl()
                && is_resource($sink)
                && ($metadata['uri'] ?? null) === 'php://memory'
                && ($options['decode_content'] ?? null) === false
                && ($options['allow_redirects'] ?? null) === false
                && is_callable($options['on_headers'] ?? null)
                && is_callable($options['progress'] ?? null);

            return Http::response(
                $this->gzip($this->rinex()),
                200,
                ['Content-Type' => 'application/gzip'],
            );
        });

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertTrue($result['complete']);
        $this->assertTrue($guarded);
        Http::assertSentCount(1);
    }

    public function test_progress_guard_aborts_an_unbounded_chunked_transfer_fail_closed(): void
    {
        $attempts = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) use (&$attempts) {
            unset($request);
            $attempts++;
            ($options['progress'])(0, BkgGnssForecastService::MAX_COMPRESSED_BYTES + 1, 0, 0);

            return Http::response('', 200, ['Content-Type' => 'application/gzip']);
        });

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertFalse($result['complete']);
        $this->assertTrue($result['stale']);
        $this->assertNull($result['counts']['visible']);
        $this->assertSame(2, $attempts);
    }

    public function test_valid_rank_deficient_geometry_is_complete_but_explicitly_insufficient(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            $this->currentUrl() => Http::response(
                $this->gzip($this->rinex(degenerateGeometry: true)),
                200,
                ['Content-Type' => 'application/gzip'],
            ),
            '*' => Http::response('', 404),
        ]);

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertTrue($result['complete']);
        $this->assertFalse($result['stale']);
        $this->assertTrue($result['pdop']['complete']);
        $this->assertFalse($result['pdop']['geometry_sufficient']);
        $this->assertSame(2, $result['pdop']['sample_count']);
        $this->assertSame(0, $result['pdop']['value_sample_count']);
        $this->assertNull($result['pdop']['value']);
        Http::assertSentCount(1);
    }

    public function test_waits_for_a_cold_calculation_lock_before_failing_closed(): void
    {
        Http::preventStrayRequests();
        $service = app(BkgGnssForecastService::class);
        $heldKey = $this->calculationLockKey($service);
        $lock = Cache::lock($heldKey.':lock', 5);
        $this->assertTrue($lock->get());

        $started = hrtime(true);
        try {
            $result = $service->forResolution($this->resolution());
        } finally {
            $lock->release();
        }
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        $this->assertFalse($result['complete']);
        $this->assertGreaterThanOrEqual(75, $elapsedMilliseconds);
        $this->assertLessThan(500, $elapsedMilliseconds);
        Http::assertNothingSent();
    }

    public function test_waits_for_current_source_lock_without_fetching_the_previous_day(): void
    {
        Http::preventStrayRequests();
        $lock = Cache::lock(
            'wallboard:uav-forecast:gnss-brdc:v1:source:20260731:lock',
            5,
        );
        $this->assertTrue($lock->get());

        $started = hrtime(true);
        try {
            $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());
        } finally {
            $lock->release();
        }
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        $this->assertFalse($result['complete']);
        $this->assertGreaterThanOrEqual(75, $elapsedMilliseconds);
        $this->assertLessThan(500, $elapsedMilliseconds);
        Http::assertNothingSent();
    }

    public function test_valid_last_good_source_bridges_a_short_bkg_outage_without_local_files(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            $this->currentUrl() => Http::response(
                $this->gzip($this->rinex()),
                200,
                ['Content-Type' => 'application/gzip'],
            ),
            '*' => Http::response('', 404),
        ]);
        $service = app(BkgGnssForecastService::class);
        $filesBefore = Storage::disk('local')->allFiles();
        $first = $service->forResolution($this->resolution());
        $this->assertTrue($first['complete']);

        CarbonImmutable::setTestNow('2026-07-31T12:28:30Z');
        Http::fake(['*' => Http::failedConnection('temporary outage')]);
        $fallback = $service->forResolution($this->resolution());

        $this->assertTrue($fallback['complete']);
        $this->assertFalse($fallback['stale']);
        $this->assertSame($first['source']['fetched_at'], $fallback['source']['fetched_at']);
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles());
        Http::assertSentCount(1);
    }

    public function test_it_uses_only_the_bounded_previous_day_fallback_when_current_is_unavailable(): void
    {
        CarbonImmutable::setTestNow('2026-08-01T00:12:30Z');
        $previousEpoch = CarbonImmutable::parse('2026-07-31T23:55:00Z');
        Http::preventStrayRequests();
        Http::fake([
            $this->currentUrl() => Http::response('', 404),
            $this->previousUrl() => Http::response(
                $this->gzip($this->rinex($previousEpoch)),
                200,
                ['Content-Type' => 'application/octet-stream'],
            ),
        ]);

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertTrue($result['complete']);
        $this->assertSame($this->previousUrl(), $result['source']['url']);
        $this->assertSame(['2026-07-31'], $result['source']['file_dates']);
        Http::assertSentCount(2);
    }

    public function test_malformed_rinex_fails_closed_after_the_two_bounded_dates(): void
    {
        $malformed = str_replace('     3.05', '     4.00', $this->rinex());
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(
            $this->gzip($malformed),
            200,
            ['Content-Type' => 'application/gzip'],
        )]);

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertFalse($result['complete']);
        $this->assertTrue($result['stale']);
        $this->assertNull($result['counts']['visible']);
        $this->assertNull($result['counts']['usable']);
        $this->assertNull($result['pdop']['value']);
        Http::assertSentCount(2);
    }

    public function test_oversized_and_truncated_gzip_responses_fail_closed(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(
                "\x1f\x8b".str_repeat('x', 40),
                200,
                [
                    'Content-Type' => 'application/gzip',
                    'Content-Length' => (string) (BkgGnssForecastService::MAX_COMPRESSED_BYTES + 1),
                ],
            )
            ->push("\x1f\x8b".str_repeat('x', 40), 200, ['Content-Type' => 'application/gzip']);

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertFalse($result['complete']);
        $this->assertNull($result['ephemeris']['satellite_count']);
        Http::assertSentCount(2);
    }

    public function test_stale_ephemerides_are_never_presented_as_current_counts(): void
    {
        $staleEpoch = CarbonImmutable::parse('2026-07-31T01:00:00Z');
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(
            $this->gzip($this->rinex($staleEpoch)),
            200,
            ['Content-Type' => 'application/gzip'],
        )]);

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertFalse($result['complete']);
        $this->assertTrue($result['stale']);
        $this->assertNull($result['counts']['visible_by_constellation']['gps']);
        $this->assertNull($result['counts']['visible_by_constellation']['galileo']);
        Http::assertSentCount(2);
    }

    public function test_network_failure_fails_closed_and_does_not_loop_beyond_two_dates(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::failedConnection('upstream unavailable')]);

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertFalse($result['complete']);
        $this->assertTrue($result['stale']);
        Http::assertSentCount(2);
    }

    public function test_galileo_other_signal_health_bits_do_not_discard_healthy_e1_b_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            $this->currentUrl() => Http::response(
                $this->gzip($this->rinex(galileoHealth: 8, galileoDataSources: 517)),
                200,
                ['Content-Type' => 'application/gzip'],
            ),
            '*' => Http::response('', 404),
        ]);

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertTrue($result['complete']);
        $this->assertSame(24, $result['ephemeris']['galileo']);
    }

    public function test_unhealthy_gps_or_e1_b_records_are_excluded_fail_closed(): void
    {
        config(['dis.wallboards.uav_forecast.gnss_min_ephemerides_per_constellation' => 24]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(
            $this->gzip($this->rinex(gpsHealth: 1, galileoHealth: 1, galileoDataSources: 517)),
            200,
            ['Content-Type' => 'application/gzip'],
        )]);

        $result = app(BkgGnssForecastService::class)->forResolution($this->resolution());

        $this->assertFalse($result['complete']);
        $this->assertNull($result['counts']['visible']);
    }

    public function test_invalid_or_incomplete_resolution_never_reaches_bkg(): void
    {
        Http::preventStrayRequests();

        $result = app(BkgGnssForecastService::class)->forResolution([
            'complete' => false,
            'expected_locations' => 1,
            'locations' => [],
        ]);

        $this->assertFalse($result['complete']);
        $this->assertSame(0, $result['location_count']);
        Http::assertNothingSent();
    }

    public function test_application_binds_the_official_service_to_the_forecast_contract(): void
    {
        $this->assertInstanceOf(
            BkgGnssForecastService::class,
            app(GnssForecastProvider::class),
        );
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float}>|null  $locations
     * @return array<string, mixed>
     */
    private function resolution(?array $locations = null): array
    {
        $locations ??= [
            ['label' => 'Utrecht', 'latitude' => 52.0907, 'longitude' => 5.1214],
            ['label' => 'Groningen', 'latitude' => 53.2194, 'longitude' => 6.5665],
        ];

        return [
            'complete' => true,
            'expected_locations' => count($locations),
            'locations' => $locations,
        ];
    }

    private function rinex(
        ?CarbonImmutable $epoch = null,
        int $gpsHealth = 0,
        int $galileoHealth = 8,
        int $galileoDataSources = 517,
        bool $degenerateGeometry = false,
    ): string {
        $epoch ??= CarbonImmutable::parse('2026-07-31T12:00:00Z');
        $lines = [
            $this->headerLine(
                sprintf('%9s%11s%-20s%-20s', '3.05', '', 'N: GNSS NAV DATA', 'M: MIXED'),
                'RINEX VERSION / TYPE',
            ),
            $this->headerLine('DIS TEST FIXTURE', 'PGM / RUN BY / DATE'),
            $this->headerLine('', 'END OF HEADER'),
        ];
        $toe = $epoch->getTimestamp()
            - $epoch->startOfDay()->subDays($epoch->dayOfWeek)->getTimestamp();
        $lines = array_merge($lines, $this->glonassRecord($epoch));
        for ($index = 0; $index < 24; $index++) {
            $plane = $degenerateGeometry ? 0 : $index % 6;
            $slot = $degenerateGeometry ? 0 : intdiv($index, 6);
            $lines = array_merge($lines, $this->navigationRecord(
                sprintf('G%02d', $index + 1),
                $epoch,
                (float) $toe,
                ($slot * pi() / 2) + (($plane % 2) * 0.14),
                $plane * pi() / 3,
                5_153.7955,
                $gpsHealth,
                1,
            ));
            $lines = array_merge($lines, $this->navigationRecord(
                sprintf('E%02d', $index + 1),
                $epoch,
                (float) $toe,
                $degenerateGeometry
                    ? 0
                    : ($slot * pi() / 2) + 0.37 + (($plane % 2) * 0.11),
                $degenerateGeometry ? 0 : ($plane * pi() / 3) + 0.19,
                5_440.0,
                $galileoHealth,
                $galileoDataSources,
            ));
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private function glonassRecord(CarbonImmutable $epoch): array
    {
        return [
            'R01 '.$epoch->format('Y m d H i s').$this->field(0).$this->field(0).$this->field(0),
            $this->continuation([19_100_000, 0, 0, 0]),
            $this->continuation([13_400_000, 0, 0, 0]),
            $this->continuation([21_500_000, 0, 0, 0]),
            $this->continuation([0, 0, 0, 0]),
        ];
    }

    /** @return list<string> */
    private function navigationRecord(
        string $satellite,
        CarbonImmutable $epoch,
        float $toe,
        float $m0,
        float $omega0,
        float $sqrtA,
        int $health,
        int $dataSources,
    ): array {
        $first = $satellite.' '.$epoch->format('Y m d H i s')
            .$this->field(0).$this->field(0).$this->field(0);

        return [
            $first,
            $this->continuation([0, 0, 4.5e-9, $m0]),
            $this->continuation([0, 0.01, 0, $sqrtA]),
            $this->continuation([$toe, 0, $omega0, 0]),
            $this->continuation([0.96, 0, 0.2, -8.0e-9]),
            $this->continuation([0, $dataSources, 2300, 0]),
            $this->continuation([1, $health, 0, 0]),
            $this->continuation([$toe, 0, 0, 0]),
        ];
    }

    /** @param list<int|float> $values */
    private function continuation(array $values): string
    {
        return '    '.implode('', array_map($this->field(...), $values));
    }

    private function field(int|float $value): string
    {
        $formatted = preg_replace_callback(
            '/E([+-])(\d+)\z/',
            static fn (array $match): string => 'D'.$match[1].str_pad($match[2], 2, '0', STR_PAD_LEFT),
            sprintf('%.12E', $value),
        );
        if (! is_string($formatted)) {
            throw new \RuntimeException('Unable to format test RINEX number.');
        }

        return str_pad($formatted, 19, ' ', STR_PAD_LEFT);
    }

    private function headerLine(string $body, string $label): string
    {
        return str_pad(substr($body, 0, 60), 60).str_pad(substr($label, 0, 20), 20);
    }

    private function gzip(string $contents): string
    {
        $gzip = gzencode($contents, 9, ZLIB_ENCODING_GZIP);
        if (! is_string($gzip)) {
            throw new \RuntimeException('Unable to create test gzip.');
        }

        return $gzip;
    }

    private function currentUrl(): string
    {
        return $this->brdcUrl(CarbonImmutable::now('UTC')->startOfDay());
    }

    private function previousUrl(): string
    {
        return $this->brdcUrl(CarbonImmutable::now('UTC')->startOfDay()->subDay());
    }

    private function brdcUrl(CarbonImmutable $date): string
    {
        $year = $date->format('Y');
        $day = sprintf('%03d', ((int) $date->format('z')) + 1);

        return "https://igs.bkg.bund.de/root_ftp/IGS/BRDC/{$year}/{$day}/"
            ."BRDC00WRD_S_{$year}{$day}0000_01D_MN.rnx.gz";
    }

    private function calculationLockKey(BkgGnssForecastService $service): string
    {
        $locationsMethod = new \ReflectionMethod($service, 'validatedLocations');
        $locations = $locationsMethod->invoke($service, $this->resolution());
        $maskMethod = new \ReflectionMethod($service, 'elevationMask');
        $mask = $maskMethod->invoke($service);
        $bucketMethod = new \ReflectionMethod($service, 'calculationBucket');
        $bucket = $bucketMethod->invoke($service, CarbonImmutable::now('UTC'));
        $keyMethod = new \ReflectionMethod($service, 'calculationCacheKey');

        return $keyMethod->invoke($service, $locations, $bucket, $mask);
    }
}
