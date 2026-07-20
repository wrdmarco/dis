<?php

namespace Tests\Feature;

use App\Models\KnmiForecastSnapshot;
use App\Services\KnmiHarmonieCloudService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

final class KnmiHarmonieCloudServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-20T12:15:00Z');
        Cache::flush();
        $this->storageRoot = storage_path('framework/testing/knmi-cloud-'.strtolower((string) Str::ulid()));
        File::makeDirectory($this->storageRoot, 0770, true);
        config()->set([
            'dis.knmi_forecast.storage_root' => $this->storageRoot,
            'dis.knmi_forecast.maximum_model_age_seconds' => 21600,
            'dis.knmi_forecast.maximum_valid_offset_seconds' => 3600,
            'dis.knmi_forecast.integrity_cache_seconds' => 900,
            'dis.knmi_forecast.point_cache_seconds' => 21600,
            'dis.knmi_forecast.query_timeout_seconds' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        File::deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function test_it_reads_all_cloud_fields_in_one_argument_safe_eccodes_process(): void
    {
        $this->snapshot();
        Process::fake(fn () => Process::result(output: $this->validOutput()));

        $reading = app(KnmiHarmonieCloudService::class)->forResolution($this->addressResolution());

        $this->assertTrue($reading['complete']);
        $this->assertFalse($reading['stale']);
        $this->assertSame(64.0, $reading['cloud_cover_pct']);
        $this->assertSame(28.0, $reading['cloud_cover_low_pct']);
        $this->assertSame(42.0, $reading['cloud_cover_mid_pct']);
        $this->assertSame(31.0, $reading['cloud_cover_high_pct']);
        $this->assertSame(760.0, $reading['cloud_base_m']);
        $this->assertSame('single_grid_point', $reading['cloud_base_aggregation']);
        $this->assertSame('2026-07-20T09:00:00+00:00', $reading['model_run_at']);
        $this->assertSame('2026-07-20T12:00:00+00:00', $reading['valid_at']);

        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;

            return is_array($command)
                && $command[0] === '/usr/bin/grib_get'
                && in_array('indicatorOfParameter:i=71/73/74/75/186', $command, true)
                && in_array('52.0907000,5.1214000,1', $command, true)
                && ! in_array('--range', $command, true);
        });
        Process::assertRanTimes(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === '/usr/bin/grib_get', 1);
    }

    public function test_missing_model_cloud_base_does_not_discard_valid_layer_cover(): void
    {
        $this->snapshot();
        Process::fake(['*' => Process::result(output: $this->validOutput(cloudBase: 9999))]);

        $reading = app(KnmiHarmonieCloudService::class)->forResolution($this->addressResolution());

        $this->assertTrue($reading['complete']);
        $this->assertSame(28.0, $reading['cloud_cover_low_pct']);
        $this->assertNull($reading['cloud_base_m']);
        $this->assertSame(0, $reading['cloud_base_sample_count']);
    }

    public function test_national_missing_points_use_one_bounded_parallel_pool_and_complete_resolution_cache(): void
    {
        $this->snapshot();
        $factory = new class extends ProcessFactory
        {
            public int $poolCalls = 0;

            public function pool(callable $callback)
            {
                $this->poolCalls++;

                return parent::pool($callback);
            }
        };
        Process::swap($factory);
        $call = 0;
        Process::fake(function () use (&$call) {
            $call++;

            return Process::result(output: $this->validOutput(cloudBase: 900 - $call * 10));
        });
        $locations = array_values((array) config('dis.wallboards.uav_forecast.province_reference_points'));

        $reading = app(KnmiHarmonieCloudService::class)->forResolution([
            'complete' => true,
            'locations' => $locations,
            'expected_locations' => 12,
        ]);
        $cached = app(KnmiHarmonieCloudService::class)->forResolution([
            'complete' => true,
            'locations' => $locations,
            'expected_locations' => 12,
        ]);

        $this->assertTrue($reading['complete']);
        $this->assertSame($reading, $cached);
        $this->assertSame(12, $reading['sample_count']);
        $this->assertSame(12, $reading['cloud_base_sample_count']);
        $this->assertSame(780.0, $reading['cloud_base_m']);
        $this->assertSame('minimum_of_province_samples', $reading['cloud_base_aggregation']);
        $this->assertSame(1, $factory->poolCalls);
        $this->assertSame(12, $call);
        Process::assertRanTimes(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === '/usr/bin/grib_get'
            && $process->timeout === 10, 12);
    }

    public function test_failed_resolution_is_negatively_cached_without_retrying_processes(): void
    {
        $this->snapshot();
        $calls = 0;
        Process::fake(function () use (&$calls) {
            $calls++;

            return Process::result(exitCode: 1);
        });
        $service = app(KnmiHarmonieCloudService::class);

        $first = $service->forResolution($this->addressResolution());
        $second = $service->forResolution($this->addressResolution());

        $this->assertFalse($first['complete']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
        Process::assertRanTimes(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === '/usr/bin/grib_get', 1);
    }

    public function test_resolution_lock_contention_fails_closed_without_waiting_or_running_eccodes(): void
    {
        $snapshot = $this->snapshot();
        Process::fake();
        $service = app(KnmiHarmonieCloudService::class);
        $member = $snapshot->manifest['members'][3];
        $method = new \ReflectionMethod($service, 'resolutionCacheKey');
        $cacheKey = $method->invoke(
            $service,
            (string) $snapshot->id,
            $member['sha256'],
            1,
            [['latitude' => 52.0907, 'longitude' => 5.1214]],
        );
        $lock = Cache::lock($cacheKey.':lock', 30);
        $this->assertTrue($lock->get());

        try {
            $reading = $service->forResolution($this->addressResolution());
        } finally {
            $lock->release();
        }

        $this->assertFalse($reading['complete']);
        $this->assertStringContainsString('al uitgelezen', $reading['availability_note']);
        Process::assertNothingRan();
    }

    public function test_truncated_member_after_warm_cache_fails_closed_before_reusing_resolution(): void
    {
        $snapshot = $this->snapshot();
        $calls = 0;
        Process::fake(function () use (&$calls) {
            $calls++;

            return Process::result(output: $this->validOutput());
        });
        $service = app(KnmiHarmonieCloudService::class);
        $warm = $service->forResolution($this->addressResolution());
        $member = $snapshot->manifest['members'][3];
        $path = $this->storageRoot.'/'.$snapshot->release_directory.'/'.$member['filename'];

        file_put_contents($path, 'tiny');
        $afterTruncate = $service->forResolution($this->addressResolution());

        $this->assertTrue($warm['complete']);
        $this->assertFalse($afterTruncate['complete']);
        $this->assertSame(1, $calls);
        Process::assertRanTimes(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === '/usr/bin/grib_get', 1);
    }

    public function test_invalid_or_stale_model_data_fails_closed_without_running_eccodes(): void
    {
        $snapshot = $this->snapshot(modelRunAt: '2026-07-20T05:00:00Z');
        Process::fake();

        $stale = app(KnmiHarmonieCloudService::class)->forResolution($this->addressResolution());
        $this->assertFalse($stale['complete']);
        $this->assertTrue($stale['stale']);
        Process::assertNothingRan();

        $snapshot->delete();
        $snapshot = $this->snapshot();
        $member = $snapshot->manifest['members'][3];
        file_put_contents($this->storageRoot.'/'.($snapshot->release_directory).'/'.$member['filename'], 'tampered');
        Cache::flush();

        $corrupt = app(KnmiHarmonieCloudService::class)->forResolution($this->addressResolution());
        $this->assertFalse($corrupt['complete']);
        $this->assertFalse($corrupt['stale']);
        Process::assertNothingRan();
    }

    public function test_unexpected_parameter_metadata_or_timestamp_is_rejected(): void
    {
        $this->snapshot();
        $invalid = str_replace('73 105 0 0', '73 100 0 0', $this->validOutput());
        Process::fake(['*' => Process::result(output: $invalid)]);

        $reading = app(KnmiHarmonieCloudService::class)->forResolution($this->addressResolution());

        $this->assertFalse($reading['complete']);
        $this->assertNull($reading['cloud_base_m']);
    }

    private function snapshot(string $modelRunAt = '2026-07-20T09:00:00Z'): KnmiForecastSnapshot
    {
        $releaseId = (string) Str::ulid();
        $relativeDirectory = 'releases/'.$releaseId;
        $releaseDirectory = $this->storageRoot.'/releases/'.$releaseId;
        File::makeDirectory($releaseDirectory, 0770, true);
        $run = CarbonImmutable::parse($modelRunAt)->utc();
        $sourceFilename = 'HARM43_V1_P1_'.$run->format('YmdH').'.tar';
        $sourceSize = 861009920;
        $sourceSha256 = str_repeat('a', 64);
        $closestLead = max(0, min(60, (int) round((CarbonImmutable::now('UTC')->getTimestamp() - $run->getTimestamp()) / 3600)));
        $members = [];
        for ($lead = 0; $lead <= 60; $lead++) {
            $filename = sprintf('HA43_N20_%s_%03d00_GB', $run->format('YmdHi'), $lead);
            $bytes = 'GRIBtest7777';
            if ($lead === $closestLead) {
                file_put_contents($releaseDirectory.'/'.$filename, $bytes);
            }
            $members[] = [
                'filename' => $filename,
                'lead_hours' => $lead,
                'valid_at' => $run->addHours($lead)->toIso8601String(),
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ];
        }

        return KnmiForecastSnapshot::query()->create([
            'dataset' => 'harmonie_arome_cy43_p1',
            'dataset_version' => '1.0',
            'source_filename' => $sourceFilename,
            'source_size_bytes' => $sourceSize,
            'source_sha256' => $sourceSha256,
            'model_run_at' => $run,
            'forecast_start_at' => $run,
            'forecast_end_at' => $run->addHours(60),
            'member_count' => 61,
            'release_directory' => $relativeDirectory,
            'manifest' => [
                'version' => 1,
                'dataset' => 'harmonie_arome_cy43_p1',
                'dataset_version' => '1.0',
                'source_filename' => $sourceFilename,
                'source_size_bytes' => $sourceSize,
                'source_sha256' => $sourceSha256,
                'model_run_at' => $run->toIso8601ZuluString(),
                'forecast_start_at' => $run->toIso8601ZuluString(),
                'forecast_end_at' => $run->addHours(60)->toIso8601ZuluString(),
                'members' => $members,
            ],
            'active_key' => KnmiForecastSnapshot::ACTIVE_KEY,
            'activated_at' => '2026-07-20T11:55:00Z',
        ]);
    }

    /** @return array<string, mixed> */
    private function addressResolution(): array
    {
        return [
            'complete' => true,
            'locations' => [[
                'label' => 'Utrecht',
                'latitude' => 52.0907,
                'longitude' => 5.1214,
            ]],
            'expected_locations' => 1,
        ];
    }

    private function validOutput(float $cloudBase = 760): string
    {
        return implode("\n", [
            '71 105 0 0 20260720 900 20260720 1200 0 0 9999 0.64',
            '73 105 0 0 20260720 900 20260720 1200 0 0 9999 0.28',
            '74 105 0 0 20260720 900 20260720 1200 0 0 9999 0.42',
            '75 105 0 0 20260720 900 20260720 1200 0 0 9999 0.31',
            '186 200 0 0 20260720 900 20260720 1200 1 119444 9999 '.$cloudBase,
        ])."\n";
    }
}
