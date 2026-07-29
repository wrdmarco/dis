<?php

namespace Tests\Feature;

use App\Services\DwdRadarConfiguration;
use App\Services\EumetsatLightningConfiguration;
use App\Services\OperationalRadarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LiveOperationalRadarServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-29T16:40:00Z');
        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_live_metadata_exposes_observations_forecast_and_same_origin_frame_urls(): void
    {
        $this->fakeCapabilities();

        $metadata = app(OperationalRadarService::class)->metadata();
        $precipitation = $metadata['precipitation'];
        $lightning = $metadata['lightning'];

        $this->assertSame('available', $precipitation['status']);
        $this->assertSame('image_frames', $precipitation['render_mode']);
        $this->assertSame('2026-07-29T16:35:00+00:00', $precipitation['reference_time']);
        $this->assertSame(300, $precipitation['age_seconds']);
        $this->assertSame([
            'crs' => 'EPSG:4326',
            'west' => 2.5,
            'south' => 50.5,
            'east' => 7.8,
            'north' => 53.7,
        ], $precipitation['bounds']);
        $this->assertNull($precipitation['atlas_url']);
        $this->assertSame(0, $precipitation['atlas_columns']);
        $this->assertSame(0, $precipitation['atlas_rows']);
        $this->assertCount(37, $precipitation['frames']);
        $this->assertSame(-60, $precipitation['frames'][0]['lead_minutes']);
        $this->assertSame('observation', $precipitation['frames'][0]['phase']);
        $this->assertSame(0, $precipitation['frames'][12]['lead_minutes']);
        $this->assertSame('observation', $precipitation['frames'][12]['phase']);
        $this->assertSame(5, $precipitation['frames'][13]['lead_minutes']);
        $this->assertSame('forecast', $precipitation['frames'][13]['phase']);
        $this->assertMatchesRegularExpression(
            '#\A/api/operational-weather/radar/precipitation/\d{8}T\d{6}Z-f\d{8}T\d{6}Z-[a-f0-9]{16}\.png\z#D',
            $precipitation['frames'][13]['image_url'],
        );
        $this->assertSame('DWD RV neerslagradar', $precipitation['source']['name']);
        $this->assertSame('CC BY 4.0', $precipitation['source']['license']);

        $this->assertSame('available', $lightning['status']);
        $this->assertSame('image_frames', $lightning['render_mode']);
        $this->assertSame('2026-07-29T16:35:00+00:00', $lightning['reference_time']);
        $this->assertSame('2026-07-29T16:40:00+00:00', $lightning['observed_period_end']);
        $this->assertSame(0, $lightning['age_seconds']);
        $this->assertCount(7, $lightning['frames']);
        $this->assertSame(-30, $lightning['frames'][0]['lead_minutes']);
        $this->assertSame(0, $lightning['frames'][6]['lead_minutes']);
        $this->assertSame('observation', $lightning['frames'][6]['phase']);
        $this->assertSame('CC BY 4.0', $lightning['source']['license']);
        $this->assertSame(
            'Contains modified EUMETSAT Meteosat data 2026',
            $lightning['source']['attribution'],
        );
        $this->assertTrue($lightning['source']['modified']);
        $this->assertSame('DIS', $lightning['source']['processed_by']);
        $this->assertMatchesRegularExpression(
            '#\A/api/operational-weather/radar/lightning/\d{8}T\d{6}Z-o-[a-f0-9]{16}\.png\z#D',
            $lightning['frames'][6]['image_url'],
        );

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->data()['request'] === 'GetCapabilities'
            && str_contains($request->url(), 'maps.dwd.de'));
        Http::assertSent(fn (Request $request): bool => $request->data()['request'] === 'GetCapabilities'
            && str_contains($request->url(), 'view.eumetsat.int'));
    }

    public function test_frames_are_fetched_via_fixed_sources_cached_in_memory_and_never_written_to_disk(): void
    {
        $dwdPng = $this->png(960, 580);
        $lightningPng = $this->png(640, 384);
        $dwdGetMapAttempts = 0;
        $lightningGetMapAttempts = 0;
        Http::fake(function (Request $request) use (
            $dwdPng,
            $lightningPng,
            &$dwdGetMapAttempts,
            &$lightningGetMapAttempts,
        ) {
            $query = $request->data();
            if (($query['request'] ?? null) === 'GetCapabilities') {
                return str_contains($request->url(), 'view.eumetsat.int')
                    ? Http::response($this->eumetsatCapabilities(), 200, ['Content-Type' => 'application/xml'])
                    : Http::response($this->dwdCapabilities(), 200, ['Content-Type' => 'application/xml']);
            }
            if (str_contains($request->url(), 'view.eumetsat.int')) {
                $lightningGetMapAttempts++;

                return Http::response($lightningPng, 200, ['Content-Type' => 'image/png']);
            }
            $dwdGetMapAttempts++;
            if (str_contains($request->url(), '://maps.dwd.de/')) {
                return Http::response('temporarily unavailable', 503, ['Content-Type' => 'text/plain']);
            }

            return Http::response($dwdPng, 200, ['Content-Type' => 'image/png']);
        });

        $service = app(OperationalRadarService::class);
        $metadata = $service->metadata();
        $dwdToken = $this->tokenFromUrl($metadata['precipitation']['frames'][13]['image_url']);
        $lightningToken = $this->tokenFromUrl($metadata['lightning']['frames'][6]['image_url']);
        Cache::flush();

        $firstDwd = $service->file('precipitation', $dwdToken);
        $secondDwd = $service->file('precipitation', $dwdToken);
        $firstLightning = $service->file('lightning', $lightningToken);
        $secondLightning = $service->file('lightning', $lightningToken);

        $this->assertNotNull($firstDwd);
        $this->assertSame($dwdPng, $firstDwd->body);
        $this->assertSame(strlen($dwdPng), $firstDwd->byteSize);
        $this->assertSame($firstDwd->sha256, $secondDwd?->sha256);
        $this->assertNotNull($firstLightning);
        $this->assertSame($lightningPng, $firstLightning->body);
        $this->assertSame(strlen($lightningPng), $firstLightning->byteSize);
        $this->assertSame($firstLightning->sha256, $secondLightning?->sha256);
        $this->assertSame(2, $dwdGetMapAttempts);
        $this->assertSame(1, $lightningGetMapAttempts);
        Http::assertSentCount(5);
    }

    public function test_earliest_observations_remain_resolvable_for_the_complete_stale_fallback_window(): void
    {
        $dwdPng = $this->png(960, 580);
        $lightningPng = $this->png(640, 384);
        Http::fake(function (Request $request) use ($dwdPng, $lightningPng) {
            $query = $request->data();
            if (($query['request'] ?? null) === 'GetCapabilities') {
                return str_contains($request->url(), 'view.eumetsat.int')
                    ? Http::response($this->eumetsatCapabilities(), 200, ['Content-Type' => 'application/xml'])
                    : Http::response($this->dwdCapabilities(), 200, ['Content-Type' => 'application/xml']);
            }

            return str_contains($request->url(), 'view.eumetsat.int')
                ? Http::response($lightningPng, 200, ['Content-Type' => 'image/png'])
                : Http::response($dwdPng, 200, ['Content-Type' => 'image/png']);
        });

        $service = app(OperationalRadarService::class);
        $metadata = $service->metadata();
        $dwdToken = $this->tokenFromUrl($metadata['precipitation']['frames'][0]['image_url']);
        $lightningToken = $this->tokenFromUrl($metadata['lightning']['frames'][0]['image_url']);

        $this->assertNotNull($service->file('precipitation', $dwdToken));
        $this->assertNotNull($service->file('lightning', $lightningToken));

        Cache::flush();
        CarbonImmutable::setTestNow('2026-07-29T17:35:00Z');

        $this->assertNotNull($service->file('precipitation', $dwdToken));

        Cache::flush();
        CarbonImmutable::setTestNow('2026-07-29T17:40:00Z');

        $this->assertNotNull($service->file('lightning', $lightningToken));
    }

    public function test_observation_urls_stay_stable_when_live_timelines_advance(): void
    {
        $advanced = false;
        Http::fake(function (Request $request) use (&$advanced) {
            if (str_contains($request->url(), 'view.eumetsat.int')) {
                $capabilities = $this->eumetsatCapabilities();
                if ($advanced) {
                    $capabilities = str_replace(
                        ['16:05:00.000Z', '16:35:00.000Z'],
                        ['16:10:00.000Z', '16:40:00.000Z'],
                        $capabilities,
                    );
                }

                return Http::response($capabilities, 200, ['Content-Type' => 'application/xml']);
            }

            $capabilities = $this->dwdCapabilities();
            if ($advanced) {
                $capabilities = str_replace(
                    ['16:35:00.000Z', '18:35:00.000Z'],
                    ['16:40:00.000Z', '18:40:00.000Z'],
                    $capabilities,
                );
            }

            return Http::response($capabilities, 200, ['Content-Type' => 'application/xml']);
        });

        $service = app(OperationalRadarService::class);
        $first = $service->metadata();
        $precipitationUrl = collect($first['precipitation']['frames'])
            ->firstWhere('valid_at', '2026-07-29T16:35:00+00:00')['image_url'];
        $lightningUrl = collect($first['lightning']['frames'])
            ->firstWhere('valid_at', '2026-07-29T16:35:00+00:00')['image_url'];

        Cache::flush();
        CarbonImmutable::setTestNow('2026-07-29T16:45:00Z');
        $advanced = true;
        $second = $service->metadata();

        $this->assertSame(
            $precipitationUrl,
            collect($second['precipitation']['frames'])
                ->firstWhere('valid_at', '2026-07-29T16:35:00+00:00')['image_url'],
        );
        $this->assertSame(
            $lightningUrl,
            collect($second['lightning']['frames'])
                ->firstWhere('valid_at', '2026-07-29T16:35:00+00:00')['image_url'],
        );
    }

    public function test_shared_frame_cache_has_a_small_bounded_retention_and_payload_budget(): void
    {
        $dwd = app(DwdRadarConfiguration::class);
        $lightning = app(EumetsatLightningConfiguration::class);

        $this->assertSame(7200, $dwd->frameCacheSeconds());
        $this->assertSame(3600, $dwd->maximumFallbackAgeSeconds());
        $this->assertSame(40, $dwd->timelineLockSeconds());
        $this->assertSame(50, $dwd->frameLockSeconds());
        $this->assertSame(262_144, $dwd->maximumFrameBytes());
        $this->assertSame(3600, $lightning->maximumFallbackAgeSeconds());
        $this->assertSame(5400, $lightning->frameCacheSeconds());
        $this->assertSame(25, $lightning->timelineLockSeconds());
        $this->assertSame(30, $lightning->frameLockSeconds());
        $this->assertSame(262_144, $lightning->maximumFrameBytes());
    }

    public function test_invalid_or_incomplete_remote_metadata_fails_closed_without_frame_urls(): void
    {
        Http::fake([
            'https://maps.dwd.de/*' => Http::response('<invalid/>', 200, ['Content-Type' => 'application/xml']),
            'https://brz-maps.dwd.de/*' => Http::response('<invalid/>', 200, ['Content-Type' => 'application/xml']),
            'https://view.eumetsat.int/*' => Http::response('<invalid/>', 200, ['Content-Type' => 'application/xml']),
        ]);

        $service = app(OperationalRadarService::class);
        $metadata = $service->metadata();

        $this->assertSame('unavailable', $metadata['precipitation']['status']);
        $this->assertSame([], $metadata['precipitation']['frames']);
        $this->assertSame('unavailable', $metadata['lightning']['status']);
        $this->assertSame([], $metadata['lightning']['frames']);
        $this->assertNull($service->file('precipitation', '20260729T163500Z-o-0000000000000000'));
        $this->assertNull($service->file('lightning', '20260729T163500Z-o-0000000000000000'));
    }

    private function fakeCapabilities(): void
    {
        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'view.eumetsat.int')
                ? Http::response($this->eumetsatCapabilities(), 200, ['Content-Type' => 'application/xml'])
                : Http::response($this->dwdCapabilities(), 200, ['Content-Type' => 'application/xml']);
        });
    }

    private function dwdCapabilities(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms">
  <Service><Name>WMS</Name><Title>DWD GeoServer WMS</Title></Service>
  <Capability><Layer><Layer>
    <Name>Radar_rv_product_1x1km_ger</Name>
    <Dimension name="time" units="ISO8601">2026-07-29T13:00:00.000Z/2026-07-29T18:35:00.000Z/PT5M</Dimension>
    <Dimension name="REFERENCE_TIME" default="2026-07-29T16:35:00.000Z" units="ISO8601">2026-07-29T16:35:00.000Z</Dimension>
  </Layer></Layer></Capability>
</WMS_Capabilities>
XML;
    }

    private function eumetsatCapabilities(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms">
  <Service><Name>WMS</Name><Title>EUMETView WMS</Title></Service>
  <Capability><Layer><Layer>
    <Name>mtg_fd:li_afa</Name>
    <Dimension name="time" units="ISO8601">2026-07-29T16:05:00.000Z/2026-07-29T16:35:00.000Z/PT5M</Dimension>
  </Layer></Layer></Capability>
</WMS_Capabilities>
XML;
    }

    private function tokenFromUrl(string $url): string
    {
        $filename = basename($url);

        return substr($filename, 0, -4);
    }

    private function png(int $width, int $height): string
    {
        $raw = str_repeat("\0".str_repeat("\0", $width * 4), $height);
        $signature = "\x89PNG\r\n\x1a\n";
        $header = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return $signature
            .$this->pngChunk('IHDR', $header)
            .$this->pngChunk('IDAT', gzcompress($raw, 9))
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
