<?php

namespace Tests\Feature;

use App\Contracts\OperationalRadarProvider;
use App\Jobs\RefreshWeatherDatasetOperation;
use App\Repositories\EumetsatLightningSnapshotRepository;
use App\Services\AdminKnmiDatasetService;
use App\Services\EumetsatLightningImportException;
use App\Services\EumetsatLightningImportService;
use App\Services\EumetsatLightningRadarService;
use App\Services\EumetsatLightningWmsClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EumetsatLightningSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://view.eumetsat.int/geoserver/wms';

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-21T12:18:00Z');
        Cache::flush();
        $this->storageRoot = storage_path('framework/testing/eumetsat-lightning-'.str()->lower((string) str()->ulid()));
        File::makeDirectory($this->storageRoot, 0770, true);
        config()->set([
            'dis.eumetsat_lightning.endpoint' => self::ENDPOINT,
            'dis.eumetsat_lightning.layer' => 'mtg_fd:li_afa',
            'dis.eumetsat_lightning.style' => 'mtg_li_afa',
            'dis.eumetsat_lightning.crs' => 'CRS:84',
            'dis.eumetsat_lightning.bbox' => [2.5, 50.5, 7.8, 53.7],
            'dis.eumetsat_lightning.frame_width' => 640,
            'dis.eumetsat_lightning.frame_height' => 384,
            'dis.eumetsat_lightning.frame_count' => 7,
            'dis.eumetsat_lightning.interval_minutes' => 5,
            'dis.eumetsat_lightning.atlas_columns' => 4,
            'dis.eumetsat_lightning.atlas_rows' => 2,
            'dis.eumetsat_lightning.storage_root' => $this->storageRoot,
            'dis.eumetsat_lightning.connect_timeout_seconds' => 2,
            'dis.eumetsat_lightning.capabilities_timeout_seconds' => 5,
            'dis.eumetsat_lightning.frame_timeout_seconds' => 5,
            'dis.eumetsat_lightning.maximum_capabilities_bytes' => 1_048_576,
            'dis.eumetsat_lightning.maximum_frame_bytes' => 4_194_304,
            'dis.eumetsat_lightning.maximum_atlas_bytes' => 33_554_432,
            'dis.eumetsat_lightning.maximum_age_seconds' => 1800,
            'dis.eumetsat_lightning.retain_releases' => 2,
            'dis.eumetsat_lightning.source_name' => 'EUMETSAT MTG Lightning Imager',
            'dis.eumetsat_lightning.source_url' => 'https://view.eumetsat.int/',
            'dis.eumetsat_lightning.license_name' => 'EUMETSAT Data Policy (vrije EUMETView-toegang)',
            'dis.eumetsat_lightning.license_url' => 'https://www.eumetsat.int/eumetsat-data-policy',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        File::deleteDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function test_command_dispatches_unique_encrypted_realtime_job_and_runs_every_five_minutes(): void
    {
        Queue::fake();

        $this->artisan('dis:refresh-eumetsat-lightning')->assertSuccessful();

        Queue::assertPushed(RefreshWeatherDatasetOperation::class, function ($job): bool {
            $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
            $this->assertInstanceOf(ShouldBeUnique::class, $job);
            $this->assertSame('knmi_realtime', $job->connection);
            $this->assertSame('knmi-realtime', $job->queue);
            $this->assertSame(1200, $job->timeout);
            $this->assertSame(1800, $job->uniqueFor);
            $this->assertGreaterThan($job->timeout, (int) config('queue.connections.knmi_realtime.retry_after'));
            $this->assertStringStartsWith('weather-dataset:', $job->uniqueId());

            return true;
        });
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $candidate): bool => str_contains(
                $candidate->command ?? '',
                'dis:refresh-eumetsat-lightning',
            ));
        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_valid_wms_import_builds_one_atomic_seven_frame_atlas(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            self::markTestSkipped('De lokale test-PHP heeft geen GD; productie installeert php8.5-gd.');
        }
        $frame = $this->png(640, 384, 38, 120, 208, 180);
        $this->fakeValidWms($frame, '2026-07-21T12:15:00.000Z');

        $result = app(EumetsatLightningImportService::class)->refresh();

        $this->assertTrue($result['changed']);
        $this->assertSame('2026-07-21T12:15:00+00:00', $result['reference_time']);
        $this->assertMatchesRegularExpression('/\A20260721T121500Z-[a-f0-9]{16}\z/D', $result['snapshot_id']);
        $snapshot = app(EumetsatLightningSnapshotRepository::class)->activeSnapshot();
        $metadata = app(EumetsatLightningRadarService::class)->metadata();
        $public = app(OperationalRadarProvider::class)->metadata()['lightning'];
        $this->assertIsArray($snapshot);
        $this->assertCount(7, $snapshot['frames']);
        $this->assertSame('2026-07-21T11:45:00+00:00', $snapshot['frames'][0]);
        $this->assertSame('2026-07-21T12:15:00+00:00', $snapshot['frames'][6]);
        $this->assertSame(4, $snapshot['atlas']['columns']);
        $this->assertSame(2, $snapshot['atlas']['rows']);
        $this->assertSame(2560, $snapshot['atlas']['width']);
        $this->assertSame(768, $snapshot['atlas']['height']);
        $this->assertSame('EUMETSAT MTG Lightning Imager', $snapshot['source']['name']);
        $this->assertStringContainsString('vrije EUMETView-toegang', $snapshot['license']['name']);
        $this->assertTrue($metadata['available']);
        $this->assertFalse($metadata['stale']);
        $this->assertSame('2026-07-21T12:20:00+00:00', $metadata['observed_period_end']);
        $this->assertSame(0, $metadata['age_seconds']);
        $this->assertSame(0, $metadata['lag_seconds']);
        $this->assertSame('available', $public['status']);
        $this->assertSame('2026-07-21T12:20:00+00:00', $public['observed_period_end']);
        $this->assertSame(-30, $public['frames'][0]['lead_minutes']);
        $this->assertSame(0, $public['frames'][6]['lead_minutes']);
        $this->assertFileExists($this->storageRoot.'/active.json');
        $this->assertSame([], glob($this->storageRoot.'/staging/*') ?: []);
        $atlas = imagecreatefrompng($snapshot['path']);
        $this->assertNotFalse($atlas);
        $lastSlot = imagecolorat($atlas, 2559, 767);
        $this->assertSame(127, ($lastSlot >> 24) & 0x7F);
        imagedestroy($atlas);

        Http::assertSentCount(8);
        Http::assertSent(function (Request $request): bool {
            $query = $this->requestQuery($request);
            if (($query['request'] ?? null) !== 'GetMap') {
                return true;
            }

            return $request->hasHeader('Accept-Encoding', 'identity')
                && ! $request->hasHeader('Authorization')
                && ($query['service'] ?? null) === 'WMS'
                && ($query['version'] ?? null) === '1.3.0'
                && ($query['layers'] ?? null) === 'mtg_fd:li_afa'
                && ($query['styles'] ?? null) === 'mtg_li_afa'
                && ($query['crs'] ?? null) === 'CRS:84'
                && ($query['bbox'] ?? null) === '2.5,50.5,7.8,53.7'
                && ($query['width'] ?? null) === '640'
                && ($query['height'] ?? null) === '384'
                && ($query['format'] ?? null) === 'image/png';
        });
    }

    public function test_capabilities_rejects_entities_oversize_redirects_and_mutable_remote_host(): void
    {
        $client = app(EumetsatLightningWmsClient::class);
        $hostileXml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY grab SYSTEM "file:///etc/passwd">]>'
            .'<WMS_Capabilities version="1.3.0"><Capability><Layer><Name>x</Name></Layer></Capability>'
            .str_repeat(' ', 128).'</WMS_Capabilities>';
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($hostileXml, 200, [
            'Content-Type' => 'text/xml',
            'Content-Length' => (string) strlen($hostileXml),
        ])]);
        try {
            $client->latestFrameTimes();
            $this->fail('An external-entity capabilities document was accepted.');
        } catch (EumetsatLightningImportException $exception) {
            $this->assertSame('capabilities_xml_invalid', $exception->publicCode);
        }

        Http::swap(new HttpClientFactory);
        $oversized = str_repeat('x', 1_048_577);
        Http::fake(['*' => Http::response($oversized, 200, ['Content-Type' => 'text/xml'])]);
        try {
            $client->latestFrameTimes();
            $this->fail('An oversized capabilities document was accepted.');
        } catch (EumetsatLightningImportException $exception) {
            $this->assertSame('capabilities_content_invalid', $exception->publicCode);
        }

        Http::swap(new HttpClientFactory);
        Http::fake(['*' => Http::response('', 302, [
            'Location' => 'https://attacker.example.test/capabilities',
            'Content-Type' => 'text/xml',
        ])]);
        try {
            $client->latestFrameTimes();
            $this->fail('A redirected capabilities request was accepted.');
        } catch (EumetsatLightningImportException $exception) {
            $this->assertSame('capabilities_download_failed', $exception->publicCode);
        }

        Http::swap(new HttpClientFactory);
        Http::fake();
        config()->set('dis.eumetsat_lightning.endpoint', 'https://attacker.example.test/wms');
        try {
            $client->latestFrameTimes();
            $this->fail('A mutable WMS endpoint was accepted.');
        } catch (EumetsatLightningImportException $exception) {
            $this->assertSame('capabilities_download_failed', $exception->publicCode);
        }
        Http::assertNothingSent();
    }

    public function test_frames_reject_redirect_wrong_content_type_signature_and_dimensions(): void
    {
        $client = app(EumetsatLightningWmsClient::class);
        $times = $this->frameTimes(CarbonImmutable::parse('2026-07-21T12:15:00Z'));
        $cases = [
            Http::response('', 302, [
                'Location' => 'https://attacker.example.test/frame.png',
                'Content-Type' => 'image/png',
            ]),
            Http::response('<html>not png</html>', 200, ['Content-Type' => 'text/html']),
            Http::response(str_repeat('x', 128), 200, ['Content-Type' => 'image/png']),
            Http::response($this->png(32, 32), 200, ['Content-Type' => 'image/png']),
        ];
        foreach ($cases as $response) {
            Http::swap(new HttpClientFactory);
            Http::preventStrayRequests();
            Http::fake(['*' => $response]);
            $staging = app(EumetsatLightningSnapshotRepository::class)->createStagingDirectory();
            try {
                $client->downloadFrames($staging, $times);
                $this->fail('A hostile EUMETSAT frame response was accepted.');
            } catch (EumetsatLightningImportException $exception) {
                $this->assertContains($exception->publicCode, [
                    'frame_download_failed',
                    'frame_content_invalid',
                ]);
            } finally {
                app(EumetsatLightningSnapshotRepository::class)->discardStaging($staging);
            }
        }
        $this->assertSame([], glob($this->storageRoot.'/staging/*') ?: []);
    }

    public function test_remote_failure_rolls_back_and_stale_snapshot_has_a_bounded_last_known_good_fallback(): void
    {
        CarbonImmutable::setTestNow('2026-07-21T12:06:00Z');
        $previous = $this->seedSnapshot(CarbonImmutable::parse('2026-07-21T12:00:00Z'));
        CarbonImmutable::setTestNow('2026-07-21T12:08:00Z');
        $capabilities = $this->capabilities('2026-07-21T12:05:00.000Z');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($capabilities) {
            return ($this->requestQuery($request)['request'] ?? null) === 'GetCapabilities'
                ? Http::response($capabilities, 200, ['Content-Type' => 'text/xml'])
                : Http::response('', 503, ['Content-Type' => 'image/png']);
        });
        try {
            app(EumetsatLightningImportService::class)->refresh();
            $this->fail('A failed EUMETSAT successor was activated.');
        } catch (EumetsatLightningImportException $exception) {
            $this->assertSame('frame_download_failed', $exception->publicCode);
        }
        $active = app(EumetsatLightningSnapshotRepository::class)->activeSnapshot();
        $this->assertSame($previous['snapshot_id'], $active['snapshot_id']);
        $this->assertSame([], glob($this->storageRoot.'/staging/*') ?: []);

        CarbonImmutable::setTestNow('2026-07-21T12:35:01Z');
        $service = app(EumetsatLightningRadarService::class);
        $stale = $service->metadata();
        $this->assertFalse($stale['available']);
        $this->assertTrue($stale['stale']);
        $this->assertSame($previous['snapshot_id'], $stale['snapshot_id']);
        $this->assertSame($previous['latest_frame_at'], $stale['latest_frame_at']);
        $this->assertSame('2026-07-21T12:05:00+00:00', $stale['observed_period_end']);
        $this->assertSame(1801, $stale['age_seconds']);
        $this->assertSame($previous['activated_at'], $stale['refreshed_at']);
        $this->assertCount(7, $stale['frames']);
        $this->assertIsArray($stale['atlas']);
        $this->assertStringContainsString('terugval', $stale['availability_note']);
        $this->assertStringContainsString('ouder dan 30 minuten', $stale['availability_note']);
        $this->assertIsArray($service->file($previous['snapshot_id']));
        $publicStale = app(OperationalRadarProvider::class)->metadata()['lightning'];
        $this->assertSame('stale', $publicStale['status']);
        $this->assertStringContainsString($previous['snapshot_id'], $publicStale['atlas_url']);
        $this->assertSame(-30, $publicStale['frames'][0]['lead_minutes']);
        $inventory = collect(app(AdminKnmiDatasetService::class)->datasets())
            ->firstWhere('key', 'eumetsat_mtg_li');
        $this->assertIsArray($inventory);
        $this->assertSame('stale', $inventory['status']);

        CarbonImmutable::setTestNow('2026-07-21T14:05:01Z');
        $expired = $service->metadata();
        $this->assertFalse($expired['available']);
        $this->assertTrue($expired['stale']);
        $this->assertNull($expired['snapshot_id']);
        $this->assertNull($expired['atlas']);
        $this->assertSame([], $expired['frames']);
        $this->assertStringContainsString('ouder dan twee uur', $expired['availability_note']);
        $this->assertNull($service->file($previous['snapshot_id']));

        CarbonImmutable::setTestNow('2026-07-21T12:07:00Z');
        $path = $previous['path'];
        $mtime = filemtime($path);
        $body = file_get_contents($path);
        $this->assertIsInt($mtime);
        $offset = strlen($body) - 20;
        $body[$offset] = $body[$offset] === 'x' ? 'y' : 'x';
        file_put_contents($path, $body, LOCK_EX);
        $this->assertTrue(touch($path, $mtime + 2));
        clearstatcache(true, $path);
        $tampered = $service->metadata();
        $this->assertFalse($tampered['available']);
        $this->assertFalse($tampered['stale']);
        $this->assertNull($tampered['snapshot_id']);
        $this->assertNull($service->file($previous['snapshot_id']));
    }

    public function test_metadata_race_serves_only_fresh_integral_active_and_exact_previous_release(): void
    {
        CarbonImmutable::setTestNow('2026-07-21T12:01:00Z');
        $first = $this->seedSnapshot(CarbonImmutable::parse('2026-07-21T12:00:00Z'));
        $firstManifest = file_get_contents(
            $this->storageRoot.'/releases/'.$first['snapshot_id'].'/manifest.json',
        );
        $firstAtlas = file_get_contents($first['path']);

        CarbonImmutable::setTestNow('2026-07-21T12:06:00Z');
        $second = $this->seedSnapshot(CarbonImmutable::parse('2026-07-21T12:05:00Z'));
        $service = app(EumetsatLightningRadarService::class);
        $metadataBeforeRefresh = $service->metadata();
        $this->assertSame($second['snapshot_id'], $metadataBeforeRefresh['snapshot_id']);

        CarbonImmutable::setTestNow('2026-07-21T12:11:00Z');
        $third = $this->seedSnapshot(CarbonImmutable::parse('2026-07-21T12:10:00Z'));
        $releases = glob($this->storageRoot.'/releases/*', GLOB_ONLYDIR) ?: [];
        $this->assertCount(2, $releases);
        $this->assertDirectoryDoesNotExist($this->storageRoot.'/releases/'.$first['snapshot_id']);
        $this->assertDirectoryExists($this->storageRoot.'/releases/'.$second['snapshot_id']);
        $this->assertDirectoryExists($this->storageRoot.'/releases/'.$third['snapshot_id']);

        $metadata = $service->metadata();
        $this->assertTrue($metadata['available']);
        $this->assertSame(7, $metadata['frame_count']);
        $this->assertCount(7, $metadata['frames']);
        $this->assertArrayNotHasKey('path', $metadata);
        $this->assertStringNotContainsString($this->storageRoot, json_encode($metadata, JSON_THROW_ON_ERROR));
        $previousFile = $service->file($metadataBeforeRefresh['snapshot_id']);
        $this->assertIsArray($previousFile);
        $this->assertSame($second['path'], $previousFile['path']);
        $activeFile = $service->file($third['snapshot_id']);
        $this->assertIsArray($activeFile);
        $this->assertSame('image/png', $activeFile['content_type']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $activeFile['sha256']);

        // Even a fully valid older directory restored behind the repository's
        // back must never become addressable as a third retained release.
        $restoredFirst = $this->storageRoot.'/releases/'.$first['snapshot_id'];
        File::makeDirectory($restoredFirst, 0770, true);
        file_put_contents($restoredFirst.'/manifest.json', $firstManifest, LOCK_EX);
        file_put_contents($restoredFirst.'/lightning-atlas.png', $firstAtlas, LOCK_EX);
        $this->assertNull($service->file($first['snapshot_id']));
        $this->assertIsArray($service->file($second['snapshot_id']));

        CarbonImmutable::setTestNow('2026-07-21T14:10:01Z');
        $this->assertNull($service->file($second['snapshot_id']));
        $this->assertIsArray($service->file($third['snapshot_id']));

        CarbonImmutable::setTestNow('2026-07-21T12:11:00Z');
        $previousMtime = filemtime($second['path']);
        $previousBody = file_get_contents($second['path']);
        $this->assertIsInt($previousMtime);
        $previousOffset = strlen($previousBody) - 20;
        $previousBody[$previousOffset] = $previousBody[$previousOffset] === 'x' ? 'y' : 'x';
        file_put_contents($second['path'], $previousBody, LOCK_EX);
        $this->assertTrue(touch($second['path'], $previousMtime + 2));
        clearstatcache(true, $second['path']);
        $this->assertNull($service->file($second['snapshot_id']));
        $this->assertIsArray($service->file($third['snapshot_id']));
    }

    public function test_unchanged_atlas_integrity_is_cached_and_a_stat_change_forces_revalidation(): void
    {
        CarbonImmutable::setTestNow('2026-07-21T12:06:00Z');
        $snapshot = $this->seedSnapshot(CarbonImmutable::parse('2026-07-21T12:00:00Z'));
        $atlasPath = $snapshot['path'];
        $cachedValues = [];
        $atlasValidationRuns = 0;
        Cache::shouldReceive('remember')
            ->atLeast()
            ->once()
            ->andReturnUsing(static function (
                string $key,
                mixed $ttl,
                callable $resolver,
            ) use (&$cachedValues, &$atlasValidationRuns): mixed {
                unset($ttl);
                if (! array_key_exists($key, $cachedValues)) {
                    $atlasValidationRuns++;
                    $cachedValues[$key] = $resolver();
                }

                return $cachedValues[$key];
            });

        $service = app(EumetsatLightningRadarService::class);
        $this->assertTrue($service->metadata()['available']);
        $this->assertIsArray($service->file($snapshot['snapshot_id']));
        $this->assertSame(1, $atlasValidationRuns);

        $originalMtime = filemtime($atlasPath);
        $contents = file_get_contents($atlasPath);
        $this->assertIsInt($originalMtime);
        $this->assertIsString($contents);
        $contents[strlen($contents) - 20] = $contents[strlen($contents) - 20] === 'x' ? 'y' : 'x';
        $this->assertSame(strlen($contents), file_put_contents($atlasPath, $contents, LOCK_EX));
        $this->assertTrue(touch($atlasPath, $originalMtime + 2));
        clearstatcache(true, $atlasPath);

        $this->assertFalse($service->metadata()['available']);
        $this->assertNull($service->file($snapshot['snapshot_id']));
        $this->assertSame(2, $atlasValidationRuns);
    }

    public function test_future_metadata_preserves_timestamps_but_remains_unusable_with_distinct_note(): void
    {
        CarbonImmutable::setTestNow('2026-07-21T12:00:00Z');
        $future = $this->seedSnapshot(CarbonImmutable::parse('2026-07-21T12:05:00Z'));
        $service = app(EumetsatLightningRadarService::class);

        $metadata = $service->metadata();

        $this->assertFalse($metadata['available']);
        $this->assertTrue($metadata['stale']);
        $this->assertNull($metadata['snapshot_id']);
        $this->assertSame($future['latest_frame_at'], $metadata['latest_frame_at']);
        $this->assertSame($future['activated_at'], $metadata['refreshed_at']);
        $this->assertSame(0, $metadata['frame_count']);
        $this->assertSame([], $metadata['frames']);
        $this->assertNull($metadata['atlas']);
        $this->assertStringContainsString('toekomst', $metadata['availability_note']);
        $this->assertStringNotContainsString('ouder dan 30 minuten', $metadata['availability_note']);
        $this->assertNull($service->file($future['snapshot_id']));
    }

    public function test_repository_and_configuration_failures_remain_fail_closed(): void
    {
        CarbonImmutable::setTestNow('2026-07-21T13:01:00Z');
        $snapshot = $this->seedSnapshot(CarbonImmutable::parse('2026-07-21T13:00:00Z'));
        $repository = app(EumetsatLightningSnapshotRepository::class);
        $service = app(EumetsatLightningRadarService::class);

        config()->set('dis.eumetsat_lightning.maximum_age_seconds', 901);
        $invalidFreshnessConfig = $service->metadata();
        $this->assertFalse($invalidFreshnessConfig['available']);
        $this->assertFalse($invalidFreshnessConfig['stale']);
        $this->assertNull($invalidFreshnessConfig['snapshot_id']);
        $this->assertSame('EUMETSAT MTG Lightning Imager', $invalidFreshnessConfig['source']['name']);
        $this->assertNull($service->file($snapshot['snapshot_id']));

        config()->set('dis.eumetsat_lightning.maximum_age_seconds', 1800);
        config()->set('dis.eumetsat_lightning.atlas_columns', 3);
        config()->set('dis.eumetsat_lightning.source_name', 'Onbetrouwbare bron');
        $this->assertNull($repository->activeSnapshot());
        $this->assertNull($repository->retainedSnapshot($snapshot['snapshot_id']));
        $invalidRepositoryConfig = $service->metadata();
        $this->assertFalse($invalidRepositoryConfig['available']);
        $this->assertNull($invalidRepositoryConfig['snapshot_id']);
        $this->assertSame('EUMETSAT MTG Lightning Imager', $invalidRepositoryConfig['source']['name']);
        $this->assertNull($service->file($snapshot['snapshot_id']));
    }

    /** @return array<string, mixed> */
    private function seedSnapshot(CarbonImmutable $latest): array
    {
        $repository = app(EumetsatLightningSnapshotRepository::class);
        $staging = $repository->createStagingDirectory();
        $path = $staging.DIRECTORY_SEPARATOR.'lightning-atlas.png';
        $body = $this->png(2560, 768, 0, 0, 0, 0);
        file_put_contents($path, $body, LOCK_EX);
        $manifest = $repository->activate($staging, $this->frameTimes($latest), [
            'path' => $path,
            'size_bytes' => strlen($body),
            'sha256' => hash('sha256', $body),
            'width' => 2560,
            'height' => 768,
        ]);
        $snapshot = $repository->activeSnapshot();
        $this->assertIsArray($snapshot);
        $this->assertSame($manifest['snapshot_id'], $snapshot['snapshot_id']);

        return $snapshot;
    }

    private function fakeValidWms(string $frame, string $end): void
    {
        $capabilities = $this->capabilities($end);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($capabilities, $frame) {
            return ($this->requestQuery($request)['request'] ?? null) === 'GetCapabilities'
                ? Http::response($capabilities, 200, [
                    'Content-Type' => 'text/xml; charset=UTF-8',
                    'Content-Length' => (string) strlen($capabilities),
                ])
                : Http::response($frame, 200, [
                    'Content-Type' => 'image/png',
                    'Content-Length' => (string) strlen($frame),
                ]);
        });
    }

    private function capabilities(string $end): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms">'
            .'<Service><Name>WMS</Name><Title>EUMETView</Title></Service><Capability><Layer><Title>Root</Title><Layer>'
            .'<Name>mtg_fd:li_afa</Name><Title>LI Accumulated Flash Area</Title>'
            .'<Dimension name="time" default="'.$end.'" units="ISO8601" nearestValue="1">'
            .'2025-05-30T15:00:00.000Z/'.$end.'/PT5M</Dimension>'
            .'</Layer></Layer></Capability></WMS_Capabilities>';
    }

    /** @return list<CarbonImmutable> */
    private function frameTimes(CarbonImmutable $latest): array
    {
        $frames = [];
        for ($index = 6; $index >= 0; $index--) {
            $frames[] = $latest->utc()->subMinutes($index * 5);
        }

        return $frames;
    }

    /** @return array<string, string> */
    private function requestQuery(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return array_filter($query, 'is_string');
    }

    private function png(
        int $width,
        int $height,
        int $red = 0,
        int $green = 0,
        int $blue = 0,
        int $alpha = 0,
    ): string {
        $pixel = pack('CCCC', $red, $green, $blue, $alpha);
        $scanline = "\0".str_repeat($pixel, $width);
        $raw = str_repeat($scanline, $height);
        $ihdr = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', $ihdr)
            .$this->pngChunk('IDAT', gzcompress($raw, 9))
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
