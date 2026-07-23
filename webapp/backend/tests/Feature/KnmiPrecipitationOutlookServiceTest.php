<?php

namespace Tests\Feature;

use App\Exceptions\KnmiPrecipitationImportException;
use App\Jobs\RefreshWeatherDatasetOperation;
use App\Models\WeatherDatasetOperation;
use App\Repositories\KnmiPrecipitationSnapshotRepository;
use App\Services\KnmiPrecipitationConfiguration;
use App\Services\KnmiPrecipitationImportService;
use App\Services\KnmiPrecipitationOutlookService;
use App\Services\WallboardForecastLocationService;
use App\Services\WeatherDatasetOperationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class KnmiPrecipitationOutlookServiceTest extends TestCase
{
    use RefreshDatabase;

    private const API_BASE = 'https://api.dataplatform.knmi.nl/open-data/v1';

    private const DOWNLOAD_HOST = 'knmi-kdp-datasets-eu-west-1.s3.eu-west-1.amazonaws.com';

    private string $storageRoot;

    private int $processCallCount = 0;

    private ?string $atlasFrameOutput = null;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-20T21:20:00Z');
        Cache::flush();
        $this->storageRoot = storage_path('framework/testing/knmi-precipitation-'.str()->lower((string) str()->ulid()));
        File::makeDirectory($this->storageRoot, 0770, true);
        config()->set([
            'dis.knmi_forecast.api_key' => 'knmi-open-data-key-123456',
            'dis.wallboards.uav_forecast.knmi_edr_api_key' => null,
            'dis.knmi_precipitation.api_base_url' => self::API_BASE,
            'dis.knmi_precipitation.download_host' => self::DOWNLOAD_HOST,
            'dis.knmi_precipitation.storage_root' => $this->storageRoot,
            'dis.knmi_precipitation.radar_dataset' => 'radar_forecast',
            'dis.knmi_precipitation.radar_version' => '2.0',
            'dis.knmi_precipitation.radar_minimum_bytes' => 8,
            'dis.knmi_precipitation.radar_maximum_bytes' => 100_000,
            'dis.knmi_precipitation.probability_dataset' => 'seamless_precipitation_ensemble_forecast_probabilities',
            'dis.knmi_precipitation.probability_version' => '1.0',
            'dis.knmi_precipitation.probability_minimum_bytes' => 8,
            'dis.knmi_precipitation.probability_maximum_bytes' => 100_000,
            'dis.knmi_precipitation.connect_timeout_seconds' => 2,
            'dis.knmi_precipitation.download_timeout_seconds' => 30,
            'dis.knmi_precipitation.retain_releases' => 2,
            'dis.knmi_precipitation.maximum_reference_age_seconds' => 1800,
            'dis.knmi_precipitation.integrity_cache_seconds' => 300,
            'dis.knmi_precipitation.point_cache_seconds' => 3600,
            'dis.knmi_precipitation.query_timeout_seconds' => 10,
        ]);
        $this->fakeH5Dump();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        File::deleteDirectory($this->storageRoot);
        Process::swap(new ProcessFactory);
        gc_collect_cycles();
        parent::tearDown();
    }

    public function test_command_dispatches_one_unique_encrypted_realtime_job(): void
    {
        Queue::fake();

        $this->artisan('dis:refresh-knmi-precipitation-outlook')->assertSuccessful();
        $operation = WeatherDatasetOperation::query()->sole();

        $this->assertSame(WeatherDatasetOperationService::RADAR, $operation->dataset_key);
        $this->assertSame([
            WeatherDatasetOperationService::RADAR,
            WeatherDatasetOperationService::PRECIPITATION_PROBABILITY,
        ], $operation->dataset_keys);
        $this->assertSame(WeatherDatasetOperation::STATE_QUEUED, $operation->state);
        $this->assertSame('knmi-precipitation', $operation->active_key);

        Queue::assertPushed(RefreshWeatherDatasetOperation::class, function ($job) use ($operation): bool {
            $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
            $this->assertInstanceOf(ShouldBeUnique::class, $job);
            $this->assertSame($operation->id, $job->operationId);
            $this->assertSame('knmi_realtime', $job->connection);
            $this->assertSame('knmi-realtime', $job->queue);
            $this->assertSame(1200, $job->timeout);
            $this->assertSame(1800, $job->uniqueFor);
            $this->assertGreaterThan($job->timeout, (int) config('queue.connections.knmi_realtime.retry_after'));
            $this->assertSame('weather-dataset:'.$operation->id, $job->uniqueId());

            return true;
        });
    }

    public function test_scheduler_checks_the_five_minute_publication_clock_after_the_files_arrive(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $candidate): bool => str_contains(
                $candidate->command ?? '',
                'dis:refresh-knmi-precipitation-outlook',
            ));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('4-59/5 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_tracked_precipitation_operation_prewarms_the_national_outlook_cache(): void
    {
        Queue::fake();
        $this->fakeRemotePair('202607202110');
        $this->artisan('dis:refresh-knmi-precipitation-outlook')->assertSuccessful();
        $operation = WeatherDatasetOperation::query()->sole();

        app(WeatherDatasetOperationService::class)->run($operation->id);

        $this->assertSame(WeatherDatasetOperation::STATE_SUCCEEDED, $operation->refresh()->state);
        $processCalls = $this->processCallCount;
        $resolution = app(WallboardForecastLocationService::class)->resolve([
            'location_mode' => WallboardForecastLocationService::MODE_NETHERLANDS,
        ]);
        $outlook = app(KnmiPrecipitationOutlookService::class)->forResolution($resolution);

        $this->assertTrue($outlook['complete']);
        $this->assertSame(12, $outlook['sample_count']);
        $this->assertSame($processCalls, $this->processCallCount);
    }

    public function test_command_is_a_controlled_noop_when_open_data_key_is_not_configured(): void
    {
        config()->set('dis.knmi_forecast.api_key');
        Queue::fake();

        $this->artisan('dis:refresh-knmi-precipitation-outlook')
            ->expectsOutputToContain('skipped')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_import_activates_newest_radar_without_waiting_for_a_matching_probability_run(): void
    {
        $pair = $this->fakeRemotePair('202607202110', radarNewerReference: '202607202115');

        $result = app(KnmiPrecipitationImportService::class)->refresh();

        $this->assertTrue($result['changed']);
        $this->assertSame('2026-07-20T21:15:00+00:00', $result['reference_time']);
        $this->assertSame([
            'status' => 'succeeded',
            'changed' => true,
            'reference_time' => '2026-07-20T21:15:00+00:00',
            'error_code' => null,
            'error_message' => null,
        ], $result['datasets']['radar_forecast']);
        $this->assertSame('unavailable', $result['datasets']['seamless_precipitation_ensemble_forecast_probabilities']['status']);
        $this->assertSame(
            'matching_run_unavailable',
            $result['datasets']['seamless_precipitation_ensemble_forecast_probabilities']['error_code'],
        );
        $snapshot = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();
        $this->assertIsArray($snapshot);
        $this->assertSame(3, $snapshot['version']);
        $this->assertSame($pair['latest_radar_filename'], $snapshot['files']['radar']['filename']);
        $this->assertArrayNotHasKey('probability', $snapshot['files']);
        $this->assertFileExists($this->storageRoot.'/active.json');
        $this->assertFileExists($snapshot['paths']['radar']);
        $this->assertArrayNotHasKey('probability', $snapshot['paths']);
        $this->assertFileExists($snapshot['paths']['atlas']);
        $this->assertSame(25, $snapshot['atlas']['frame_count']);
        $this->assertCount(25, $snapshot['atlas']['frames']);
        $this->assertSame([], glob($this->storageRoot.'/staging/*') ?: []);
        Http::assertSent(function (Request $request) use ($pair): bool {
            if ($request->url() !== $pair['latest_radar_url']) {
                return true;
            }

            return ! $request->hasHeader('Range') && ! $request->hasHeader('Authorization');
        });
        Http::assertSentCount(4);
        Process::assertRan(function (PendingProcess $process): bool {
            $command = is_array($process->command) ? $process->command : [];

            return ($command[0] ?? null) === '/usr/bin/h5dump'
                && count(array_keys($command, '-d', true)) === 25
                && $this->validRadarHyperslabArgumentOrder($command);
        });
    }

    public function test_reader_is_local_cached_and_recomputes_staleness_after_cache_hit(): void
    {
        $this->fakeRemotePair('202607202110');
        app(KnmiPrecipitationImportService::class)->refresh();
        $service = app(KnmiPrecipitationOutlookService::class);
        $resolution = $this->resolution([[51.955, 5.227]]);
        $before = $this->processCallCount;
        Http::swap(new HttpClientFactory);
        Http::preventStrayRequests();

        $first = $service->forResolution($resolution);
        $afterFirst = $this->processCallCount;
        $cached = $service->forResolution($resolution);

        $this->assertTrue($first['complete']);
        $this->assertFalse($first['stale']);
        $this->assertEqualsWithDelta(2.4, $first['radar_peak_mm_h'], 0.000001);
        $this->assertSame('2026-07-20T21:20:00+00:00', $first['radar_first_precipitation_at']);
        $this->assertSame(45.0, $first['third_hour_probability_pct']);
        $this->assertSame(25, $first['radar_sample_count']);
        $this->assertSame(13, $first['third_hour_sample_count']);
        $this->assertSame($afterFirst, $this->processCallCount);
        $this->assertSame(2, $afterFirst - $before);
        $this->assertSame($first['refreshed_at'], $cached['refreshed_at']);
        Http::assertNothingSent();

        CarbonImmutable::setTestNow('2026-07-20T21:41:00Z');
        $stale = $service->forResolution($resolution);
        $this->assertTrue($stale['complete']);
        $this->assertTrue($stale['stale']);
        $this->assertStringContainsString('fail-closed', $stale['availability_note']);
        $this->assertSame($afterFirst, $this->processCallCount);
    }

    public function test_probability_download_failure_still_activates_the_new_radar_atomically(): void
    {
        $this->fakeRemotePair('202607202110');
        app(KnmiPrecipitationImportService::class)->refresh();
        $previous = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();
        CarbonImmutable::setTestNow('2026-07-20T21:25:00Z');
        Http::swap(new HttpClientFactory);
        $this->fakeRemotePair('202607202115', partialProbability: true);

        $result = app(KnmiPrecipitationImportService::class)->refresh();

        $active = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();
        $this->assertTrue($result['changed']);
        $this->assertNotSame($previous['snapshot_id'], $active['snapshot_id']);
        $this->assertSame('2026-07-20T21:15:00+00:00', $active['reference_time']);
        $this->assertArrayNotHasKey('probability', $active['files']);
        $this->assertSame(
            'failed',
            $result['datasets']['seamless_precipitation_ensemble_forecast_probabilities']['status'],
        );
        $this->assertSame(
            'download_failed',
            $result['datasets']['seamless_precipitation_ensemble_forecast_probabilities']['error_code'],
        );
        $outlook = app(KnmiPrecipitationOutlookService::class)->forResolution(
            $this->resolution([[51.955, 5.227]]),
        );
        $this->assertTrue($outlook['complete']);
        $this->assertFalse($outlook['probability_complete']);
        $this->assertEqualsWithDelta(2.4, $outlook['radar_peak_mm_h'], 0.000001);
        $this->assertNull($outlook['third_hour_probability_pct']);
        $this->assertNull($outlook['third_hour_from']);
        $this->assertNull($outlook['forecast_until']);
        $this->assertSame(25, $outlook['radar_sample_count']);
        $this->assertSame(0, $outlook['third_hour_sample_count']);
        $this->assertSame([], glob($this->storageRoot.'/staging/*') ?: []);
        $this->assertCount(2, glob($this->storageRoot.'/releases/*', GLOB_ONLYDIR) ?: []);
    }

    public function test_probability_point_read_failure_falls_back_to_the_valid_radar_outlook(): void
    {
        $this->fakeRemotePair('202607202110');
        app(KnmiPrecipitationImportService::class)->refresh();
        Cache::flush();
        Process::fake(function (PendingProcess $process) {
            $this->processCallCount++;
            $command = is_array($process->command) ? $process->command : [];
            $datasetIndex = array_search('-d', $command, true);
            if ($datasetIndex !== false
                && ($command[$datasetIndex + 1] ?? null) === '/exceedance_probability'
                && in_array('-s', $command, true)) {
                return Process::result(exitCode: 1, errorOutput: 'probability read failed');
            }

            return $this->h5DumpResult($command);
        });

        $outlook = app(KnmiPrecipitationOutlookService::class)->forResolution(
            $this->resolution([[51.955, 5.227]]),
        );

        $this->assertTrue($outlook['complete']);
        $this->assertFalse($outlook['probability_complete']);
        $this->assertEqualsWithDelta(2.4, $outlook['radar_peak_mm_h'], 0.000001);
        $this->assertNull($outlook['third_hour_probability_pct']);
        $this->assertNull($outlook['third_hour_from']);
        $this->assertNull($outlook['forecast_until']);
        $this->assertSame(25, $outlook['radar_sample_count']);
        $this->assertSame(0, $outlook['third_hour_sample_count']);
        $this->assertStringContainsString('ensemblekans', $outlook['availability_note']);
    }

    public function test_probability_listing_failure_is_reported_without_blocking_radar_activation(): void
    {
        $this->fakeRemotePair('202607202115', probabilityListingFailure: true);

        $result = app(KnmiPrecipitationImportService::class)->refresh();
        $active = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();
        $probability = $result['datasets']['seamless_precipitation_ensemble_forecast_probabilities'];

        $this->assertTrue($result['changed']);
        $this->assertSame('succeeded', $result['datasets']['radar_forecast']['status']);
        $this->assertSame('failed', $probability['status']);
        $this->assertSame('metadata_unavailable', $probability['error_code']);
        $this->assertStringNotContainsString('503', $probability['error_message']);
        $this->assertSame(3, $active['version']);
        $this->assertArrayNotHasKey('probability', $active['files']);
        $this->assertFileExists($active['paths']['atlas']);
    }

    public function test_probability_schema_failure_is_contained_and_a_second_refresh_is_unchanged(): void
    {
        $this->fakeRemotePair('202607202115');
        Process::fake(function (PendingProcess $process) {
            $command = is_array($process->command) ? $process->command : [];
            $path = is_string(end($command)) ? end($command) : '';
            if (in_array('-H', $command, true) && str_ends_with($path, '.nc')) {
                return Process::result(exitCode: 1, errorOutput: 'invalid probability schema');
            }

            return $this->h5DumpResult($command);
        });

        $first = app(KnmiPrecipitationImportService::class)->refresh();
        $firstActive = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();
        $second = app(KnmiPrecipitationImportService::class)->refresh();
        $secondActive = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();

        $this->assertTrue($first['changed']);
        $this->assertSame(
            'hdf5_invalid',
            $first['datasets']['seamless_precipitation_ensemble_forecast_probabilities']['error_code'],
        );
        $this->assertFalse($second['changed']);
        $this->assertSame('unchanged', $second['datasets']['radar_forecast']['status']);
        $this->assertSame($firstActive['snapshot_id'], $secondActive['snapshot_id']);
        $this->assertArrayNotHasKey('probability', $secondActive['files']);
    }

    public function test_unchanged_paired_snapshot_returns_stable_per_dataset_results(): void
    {
        $this->fakeRemotePair('202607202110');
        app(KnmiPrecipitationImportService::class)->refresh();

        $result = app(KnmiPrecipitationImportService::class)->refresh();

        $this->assertFalse($result['changed']);
        $this->assertNotSame('', $result['snapshot_id']);
        $this->assertSame('unchanged', $result['datasets']['radar_forecast']['status']);
        $this->assertFalse($result['datasets']['radar_forecast']['changed']);
        $this->assertSame(
            'unchanged',
            $result['datasets']['seamless_precipitation_ensemble_forecast_probabilities']['status'],
        );
        $this->assertFalse(
            $result['datasets']['seamless_precipitation_ensemble_forecast_probabilities']['changed'],
        );
    }

    public function test_dataset_specific_maximum_size_clamps_preserve_the_128_mib_probability_boundary(): void
    {
        config()->set([
            'dis.knmi_precipitation.radar_maximum_bytes' => 999_999_999,
            'dis.knmi_precipitation.probability_maximum_bytes' => 999_999_999,
        ]);
        $configuration = app(KnmiPrecipitationConfiguration::class);

        $this->assertSame(16_777_216, $configuration->maximumBytes('radar_forecast'));
        $this->assertSame(
            134_217_728,
            $configuration->maximumBytes('seamless_precipitation_ensemble_forecast_probabilities'),
        );

        config()->set([
            'dis.knmi_precipitation.radar_maximum_bytes' => 12_345_678,
            'dis.knmi_precipitation.probability_maximum_bytes' => 134_217_728,
        ]);

        $this->assertSame(12_345_678, $configuration->maximumBytes('radar_forecast'));
        $this->assertSame(
            134_217_728,
            $configuration->maximumBytes('seamless_precipitation_ensemble_forecast_probabilities'),
        );
    }

    public function test_stale_latest_radar_uses_the_public_radar_error_code_before_any_download(): void
    {
        $this->fakeRemotePair('202607202030');

        try {
            app(KnmiPrecipitationImportService::class)->refresh();
            $this->fail('A stale radar run was accepted.');
        } catch (KnmiPrecipitationImportException $exception) {
            $this->assertSame('radar_run_stale', $exception->publicCode);
        }

        $this->assertNull(app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot());
        Http::assertSentCount(1);
    }

    public function test_radar_schema_failure_keeps_previous_snapshot_active(): void
    {
        $this->fakeRemotePair('202607202110');
        app(KnmiPrecipitationImportService::class)->refresh();
        $previous = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();
        CarbonImmutable::setTestNow('2026-07-20T21:25:00Z');
        Http::swap(new HttpClientFactory);
        $this->fakeRemotePair('202607202115');
        Process::fake(function (PendingProcess $process) {
            $command = is_array($process->command) ? $process->command : [];
            $path = is_string(end($command)) ? end($command) : '';
            if (in_array('-H', $command, true) && str_ends_with($path, '.h5')) {
                return Process::result(exitCode: 1, errorOutput: 'invalid schema');
            }

            return $this->h5DumpResult($command);
        });
        try {
            app(KnmiPrecipitationImportService::class)->refresh();
            $this->fail('Invalid HDF5 schema was activated.');
        } catch (KnmiPrecipitationImportException $exception) {
            $this->assertSame('hdf5_invalid', $exception->publicCode);
        }
        $this->assertSame(
            $previous['snapshot_id'],
            app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot()['snapshot_id'],
        );
    }

    public function test_tampered_local_file_and_oversized_resolution_fail_closed_without_process_or_network(): void
    {
        $this->fakeRemotePair('202607202110');
        app(KnmiPrecipitationImportService::class)->refresh();
        $snapshot = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();
        $radar = $snapshot['paths']['radar'];
        $body = file_get_contents($radar);
        $body[20] = $body[20] === 'x' ? 'y' : 'x';
        file_put_contents($radar, $body, LOCK_EX);
        clearstatcache(true, $radar);
        Cache::flush();
        Http::swap(new HttpClientFactory);
        Http::preventStrayRequests();
        $processCalls = $this->processCallCount;
        Process::fake();
        $service = app(KnmiPrecipitationOutlookService::class);

        $tampered = $service->forResolution($this->resolution([[51.955, 5.227]]));
        $tooMany = $service->forResolution($this->resolution(array_fill(0, 13, [51.955, 5.227])));

        $this->assertFalse($tampered['complete']);
        $this->assertFalse($tooMany['complete']);
        Http::assertNothingSent();
        $this->assertSame($processCalls, $this->processCallCount);
    }

    /**
     * @return array{
     *   radar_filename: string,
     *   latest_radar_filename: string,
     *   probability_filename: string,
     *   radar_url: string,
     *   latest_radar_url: string,
     *   probability_url: string
     * }
     */
    private function fakeRemotePair(
        string $reference,
        ?string $radarNewerReference = null,
        bool $partialProbability = false,
        bool $probabilityListingFailure = false,
    ): array {
        $radarFilename = 'RAD_NL25_RAC_FM_'.$reference.'.h5';
        $probabilityFilename = 'KNMI_PYSTEPS_BLEND_PROB_'.$reference.'.nc';
        $latestRadarFilename = 'RAD_NL25_RAC_FM_'.($radarNewerReference ?? $reference).'.h5';
        $radarBody = $this->hdfBody('r', 256);
        $probabilityBody = $this->hdfBody('p', 320);
        $radarUrl = $this->downloadUrl('radar_forecast', '2.0', $radarFilename);
        $latestRadarUrl = $this->downloadUrl('radar_forecast', '2.0', $latestRadarFilename);
        $probabilityUrl = $this->downloadUrl(
            'seamless_precipitation_ensemble_forecast_probabilities',
            '1.0',
            $probabilityFilename,
        );
        Http::fake(function (Request $request) use (

            $radarNewerReference,
            $radarFilename,
            $latestRadarFilename,
            $probabilityFilename,
            $radarBody,
            $probabilityBody,
            $radarUrl,
            $latestRadarUrl,
            $probabilityUrl,
            $partialProbability,
            $probabilityListingFailure,
        ) {
            $url = $request->url();
            if (str_contains($url, '/radar_forecast/versions/2.0/files?')) {
                $files = [[
                    'filename' => $radarFilename,
                    'size' => strlen($radarBody),
                    'created' => '2026-07-20T21:11:00Z',
                ]];
                if ($radarNewerReference !== null) {
                    array_unshift($files, [
                        'filename' => 'RAD_NL25_RAC_FM_'.$radarNewerReference.'.h5',
                        'size' => strlen($radarBody),
                        'created' => '2026-07-20T21:16:00Z',
                    ]);
                }

                return Http::response(['files' => $files]);
            }
            if (str_contains($url, '/seamless_precipitation_ensemble_forecast_probabilities/versions/1.0/files?')) {
                if ($probabilityListingFailure) {
                    return Http::response([], 503);
                }

                return Http::response(['files' => [[
                    'filename' => $probabilityFilename,
                    'size' => strlen($probabilityBody),
                    'created' => '2026-07-20T21:12:00Z',
                ]]]);
            }
            if (str_ends_with($url, '/'.$radarFilename.'/url')) {
                return Http::response(['temporaryDownloadUrl' => $radarUrl]);
            }
            if (str_ends_with($url, '/'.$latestRadarFilename.'/url')) {
                return Http::response(['temporaryDownloadUrl' => $latestRadarUrl]);
            }
            if (str_ends_with($url, '/'.$probabilityFilename.'/url')) {
                return Http::response(['temporaryDownloadUrl' => $probabilityUrl]);
            }
            if ($url === $radarUrl) {
                return Http::response($radarBody, 200, ['Content-Length' => (string) strlen($radarBody)]);
            }
            if ($url === $latestRadarUrl) {
                return Http::response($radarBody, 200, ['Content-Length' => (string) strlen($radarBody)]);
            }
            if ($url === $probabilityUrl) {
                return Http::response($probabilityBody, $partialProbability ? 206 : 200, [
                    'Content-Length' => (string) strlen($probabilityBody),
                    ...($partialProbability ? ['Content-Range' => 'bytes 0-319/320'] : []),
                ]);
            }

            return Http::response([], 500);
        });

        return compact('radarFilename', 'probabilityFilename', 'radarUrl', 'probabilityUrl') + [
            'radar_filename' => $radarFilename,
            'latest_radar_filename' => $latestRadarFilename,
            'probability_filename' => $probabilityFilename,
            'radar_url' => $radarUrl,
            'latest_radar_url' => $latestRadarUrl,
            'probability_url' => $probabilityUrl,
        ];
    }

    private function fakeH5Dump(): void
    {
        Process::fake(function (PendingProcess $process) {
            $this->processCallCount++;

            return $this->h5DumpResult(is_array($process->command) ? $process->command : []);
        });
    }

    private function h5DumpResult(array $command): mixed
    {
        $path = is_string(end($command)) ? end($command) : '';
        $reference = preg_match('/_(\d{12})\.(?:h5|nc)\z/D', $path, $matches) === 1
            ? CarbonImmutable::createFromFormat('!YmdHi', $matches[1], 'UTC')
            : CarbonImmutable::parse('2026-07-20T21:10:00Z');
        if (in_array('-H', $command, true)) {
            return Process::result(output: str_ends_with($path, '.h5')
                ? $this->radarHeader()
                : $this->probabilityHeader());
        }
        $attributeIndex = array_search('-a', $command, true);
        if ($attributeIndex !== false) {
            $attribute = $command[$attributeIndex + 1] ?? '';

            return Process::result(output: $this->attributeOutput(
                (string) $attribute,
                $reference,
                str_ends_with($path, '.h5'),
            ));
        }
        $datasetIndices = array_keys($command, '-d', true);
        if (count($datasetIndices) === 1) {
            $dataset = (string) ($command[$datasetIndices[0] + 1] ?? '');
            if (preg_match('/\A\/image([1-9]|1\d|2[0-5])\/image_data\z/D', $dataset) === 1) {
                if (in_array('-S', $command, true) || ! in_array('765,700', $command, true)) {
                    return Process::result(exitCode: 1, errorOutput: 'unexpected radar atlas dataset');
                }
                $this->atlasFrameOutput ??= 'DATASET "image_data" { DATA { (0,0): 20, '
                    .str_repeat('0, ', (765 * 700) - 1).'} }';

                return Process::result(output: $this->atlasFrameOutput);
            }
        }
        if (count($datasetIndices) === 25) {
            $blocks = [];
            for ($index = 0; $index < 25; $index++) {
                $raw = $index === 2 ? 2 : ($index === 4 ? 20 : 0);
                $blocks[] = 'DATASET "image_data" { DATA { (444,374): '.$raw.' } }';
            }

            return Process::result(output: 'HDF5 { '.implode("\n", $blocks).' }');
        }
        $dataset = $datasetIndices === [] ? '' : (string) ($command[$datasetIndices[0] + 1] ?? '');
        if ($dataset === '/exceedance_probability' && in_array('-s', $command, true)) {
            return Process::result(output: $this->dataOutput([0, 0, 5, 10, 20, 45, 30, 10, 5, 0, 0, 0, 0]));
        }
        $values = match ($dataset) {
            '/forecast_reference_time' => [0],
            '/threshold' => [0.1, 0.3, 1, 3, 10, 30],
            '/time' => array_map(static fn (int $step): int => $step * 300, range(1, 72)),
            '/x' => array_map(static fn (int $index): float => 500.00130649 + ($index * 1000.00261298), range(0, 699)),
            '/y' => array_map(static fn (int $index): float => -3650495.41359594 + ($index * -1000.00507047), range(0, 764)),
            default => [],
        };

        return $values === []
            ? Process::result(exitCode: 1, errorOutput: 'unexpected h5dump command')
            : Process::result(output: $this->dataOutput($values));
    }

    private function attributeOutput(string $attribute, CarbonImmutable $reference, bool $radar): string
    {
        if ($radar) {
            $value = match (true) {
                preg_match('/\A\/image(\d+)\/image_datetime_valid\z/D', $attribute, $matches) === 1 => strtoupper(
                    $reference->addMinutes(((int) $matches[1] - 1) * 5)->format('d-M-Y;H:i:s.000'),
                ),
                $attribute === '/geographic/map_projection/projection_proj4_params' => '+proj=stere +lat_0=90 +lon_0=0 +lat_ts=60 +a=6378.14 +b=6356.75 +x_0=0 y_0=0',
                $attribute === '/geographic/map_projection/projection_name' => 'STEREOGRAPHIC',
                $attribute === '/overview/number_image_groups' => 25,
                $attribute === '/geographic/geo_number_rows' => 765,
                $attribute === '/geographic/geo_number_columns' => 700,
                $attribute === '/geographic/geo_pixel_size_x' => 1.0000035,
                $attribute === '/geographic/geo_pixel_size_y' => -1.0000048,
                $attribute === '/image1/calibration/calibration_formulas' => 'GEO=0.010000*PV+0.000000',
                $attribute === '/image1/calibration/calibration_missing_data' => 65_534,
                $attribute === '/image1/calibration/calibration_out_of_image' => 65_535,
                $attribute === '/overview/product_datetime_start' => strtoupper($reference->format('d-M-Y;H:i:s.000')),
                $attribute === '/overview/product_datetime_end' => strtoupper($reference->addMinutes(120)->format('d-M-Y;H:i:s.000')),
                default => null,
            };
        } else {
            $origin = 'seconds since '.$reference->format('Y-m-d H:i:s');
            $value = match ($attribute) {
                '/Conventions' => 'CF-1.7',
                '/projection' => '+proj=stere +lat_0=90 +lon_0=0.0 +lat_ts=60.0 +a=6378137 +b=6356752 +x_0=0 +y_0=0',
                '/exceedance_probability/units' => 'percent',
                '/forecast_reference_time/units', '/time/units' => $origin,
                default => null,
            };
        }
        if ($value === null) {
            return 'invalid';
        }

        return 'ATTRIBUTE "value" { DATA { (0): '.(is_string($value) ? '"'.$value.'"' : $value).' } }';
    }

    private function radarHeader(): string
    {
        $groups = [];
        for ($image = 1; $image <= 25; $image++) {
            $groups[] = 'GROUP "image'.$image.'" { DATASET "image_data" {'
                .' DATATYPE H5T_STD_U16LE DATASPACE SIMPLE { ( 765, 700 ) / ( 765, 700 ) } } }';
        }

        return 'HDF5 { '.implode("\n", $groups).' }';
    }

    private function probabilityHeader(): string
    {
        return <<<'HDF'
HDF5 {
DATASET "exceedance_probability" { DATATYPE H5T_STD_U8LE DATASPACE SIMPLE { ( 6, 72, 765, 700 ) / ( 6, 72, 765, 700 ) } }
DATASET "forecast_reference_time" { DATATYPE H5T_STD_I64LE DATASPACE SCALAR }
DATASET "threshold" { DATATYPE H5T_IEEE_F64LE DATASPACE SIMPLE { ( 6 ) / ( 6 ) } }
DATASET "time" { DATATYPE H5T_STD_I64LE DATASPACE SIMPLE { ( 72 ) / ( 72 ) } }
DATASET "x" { DATATYPE H5T_IEEE_F64LE DATASPACE SIMPLE { ( 700 ) / ( 700 ) } }
DATASET "y" { DATATYPE H5T_IEEE_F64LE DATASPACE SIMPLE { ( 765 ) / ( 765 ) } }
}
HDF;
    }

    /** @param list<int|float> $values */
    private function dataOutput(array $values): string
    {
        return 'DATASET "value" { DATA { (0): '.implode(', ', $values).' } }';
    }

    /** @param list<string> $command */
    private function validRadarHyperslabArgumentOrder(array $command): bool
    {
        $cursor = 5;
        for ($image = 1; $image <= 25; $image++) {
            if (($command[$cursor] ?? null) !== '-d'
                || ($command[$cursor + 1] ?? null) !== '/image'.$image.'/image_data'
                || ($command[$cursor + 2] ?? null) !== '-s'
                || preg_match('/\A\d+,\d+\z/D', (string) ($command[$cursor + 3] ?? '')) !== 1
                || ($command[$cursor + 4] ?? null) !== '-c'
                || ($command[$cursor + 5] ?? null) !== '1,1') {
                return false;
            }
            $cursor += 6;
        }

        return true;
    }

    private function hdfBody(string $fill, int $size): string
    {
        return "\x89HDF\r\n\x1a\n".str_repeat($fill, $size - 8);
    }

    private function downloadUrl(string $dataset, string $version, string $filename): string
    {
        return 'https://'.self::DOWNLOAD_HOST.'/'.$dataset.'/'.$version.'/'.$filename.'?signature=test';
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     * @return array<string, mixed>
     */
    private function resolution(array $points): array
    {
        return [
            'mode' => count($points) === 1 ? 'address' : 'netherlands',
            'label' => 'Test',
            'locations' => array_map(
                static fn (array $point): array => [
                    'label' => 'Testpunt',
                    'latitude' => $point[0],
                    'longitude' => $point[1],
                ],
                $points,
            ),
            'expected_locations' => count($points),
            'complete' => true,
        ];
    }
}
