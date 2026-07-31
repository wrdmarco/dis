<?php

namespace Tests\Feature;

use App\Services\EumetsatLightningConfiguration;
use App\Services\KnmiRadarConfiguration;
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
            'west' => 1.0,
            'south' => 49.0,
            'east' => 10.0,
            'north' => 55.0,
        ], $precipitation['bounds']);
        $this->assertNull($precipitation['atlas_url']);
        $this->assertSame(0, $precipitation['atlas_columns']);
        $this->assertSame(0, $precipitation['atlas_rows']);
        $this->assertSame(1200, $precipitation['frame_width']);
        $this->assertSame(800, $precipitation['frame_height']);
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
        $this->assertSame('KNMI RTCOR + radar forecast 2.0', $precipitation['source']['name']);
        $this->assertSame('CC BY 4.0', $precipitation['source']['license']);
        $this->assertSame(
            'KNMI nl_rdr_data_rtcor_5m en radar_forecast_2.0',
            $precipitation['source']['attribution'],
        );

        $this->assertSame('available', $lightning['status']);
        $this->assertSame('image_frames', $lightning['render_mode']);
        $this->assertSame('2026-07-29T16:35:00+00:00', $lightning['reference_time']);
        $this->assertSame('2026-07-29T16:40:00+00:00', $lightning['observed_period_end']);
        $this->assertSame(0, $lightning['age_seconds']);
        $this->assertSame($precipitation['bounds'], $lightning['bounds']);
        $this->assertSame(960, $lightning['frame_width']);
        $this->assertSame(640, $lightning['frame_height']);
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

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => ($request->data()['request'] ?? null) === 'GetCapabilities'
            && ($request->data()['dataset'] ?? null) === 'nl_rdr_data_rtcor_5m');
        Http::assertSent(fn (Request $request): bool => ($request->data()['request'] ?? null) === 'GetCapabilities'
            && ($request->data()['dataset'] ?? null) === 'radar_forecast_2.0');
        Http::assertSent(fn (Request $request): bool => ($request->data()['request'] ?? null) === 'GetCapabilities'
            && str_contains($request->url(), 'view.eumetsat.int'));
    }

    public function test_frames_are_fetched_via_fixed_sources_cached_in_memory_and_never_written_to_disk(): void
    {
        $knmiPng = $this->png(1200, 800);
        $lightningPng = $this->png(960, 640);
        $knmiGetMapAttempts = 0;
        $lightningGetMapAttempts = 0;
        Http::fake(function (Request $request) use (
            $knmiPng,
            $lightningPng,
            &$knmiGetMapAttempts,
            &$lightningGetMapAttempts,
        ) {
            $query = $request->data();
            if (($query['request'] ?? null) === 'GetCapabilities') {
                return $this->capabilitiesResponse($request);
            }
            if (str_contains($request->url(), 'view.eumetsat.int')) {
                $lightningGetMapAttempts++;

                return Http::response($lightningPng, 200, ['Content-Type' => 'image/png']);
            }
            $knmiGetMapAttempts++;

            return Http::response($knmiPng, 200, ['Content-Type' => 'image/png']);
        });

        $service = app(OperationalRadarService::class);
        $metadata = $service->metadata();
        $knmiToken = $this->tokenFromUrl($metadata['precipitation']['frames'][13]['image_url']);
        $lightningToken = $this->tokenFromUrl($metadata['lightning']['frames'][6]['image_url']);
        Cache::flush();

        $firstKnmi = $service->file('precipitation', $knmiToken);
        $secondKnmi = $service->file('precipitation', $knmiToken);
        $firstLightning = $service->file('lightning', $lightningToken);
        $secondLightning = $service->file('lightning', $lightningToken);

        $this->assertNotNull($firstKnmi);
        $this->assertSame($knmiPng, $firstKnmi->body);
        $this->assertSame(strlen($knmiPng), $firstKnmi->byteSize);
        $this->assertSame($firstKnmi->sha256, $secondKnmi?->sha256);
        $this->assertNotNull($firstLightning);
        $this->assertSame($lightningPng, $firstLightning->body);
        $this->assertSame(strlen($lightningPng), $firstLightning->byteSize);
        $this->assertSame($firstLightning->sha256, $secondLightning?->sha256);
        $this->assertSame(1, $knmiGetMapAttempts);
        $this->assertSame(1, $lightningGetMapAttempts);
        Http::assertSentCount(5);
        Http::assertSent(function (Request $request): bool {
            $query = $request->data();

            return str_contains($request->url(), 'view.eumetsat.int')
                && ($query['request'] ?? null) === 'GetMap'
                && ($query['crs'] ?? null) === 'CRS:84'
                && ($query['bbox'] ?? null) === '1,49,10,55'
                && ($query['width'] ?? null) === 960
                && ($query['height'] ?? null) === 640;
        });
    }

    public function test_earliest_observations_remain_resolvable_for_the_complete_stale_fallback_window(): void
    {
        $knmiPng = $this->png(1200, 800);
        $lightningPng = $this->png(960, 640);
        Http::fake(function (Request $request) use ($knmiPng, $lightningPng) {
            $query = $request->data();
            if (($query['request'] ?? null) === 'GetCapabilities') {
                return $this->capabilitiesResponse($request);
            }

            return str_contains($request->url(), 'view.eumetsat.int')
                ? Http::response($lightningPng, 200, ['Content-Type' => 'image/png'])
                : Http::response($knmiPng, 200, ['Content-Type' => 'image/png']);
        });

        $service = app(OperationalRadarService::class);
        $metadata = $service->metadata();
        $knmiToken = $this->tokenFromUrl($metadata['precipitation']['frames'][0]['image_url']);
        $lightningToken = $this->tokenFromUrl($metadata['lightning']['frames'][0]['image_url']);

        $this->assertNotNull($service->file('precipitation', $knmiToken));
        $this->assertNotNull($service->file('lightning', $lightningToken));

        Cache::flush();
        CarbonImmutable::setTestNow('2026-07-29T17:35:00Z');

        $this->assertNotNull($service->file('precipitation', $knmiToken));

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

            $dataset = $request->data()['dataset'] ?? null;
            $capabilities = $dataset === 'nl_rdr_data_rtcor_5m'
                ? $this->knmiObservationCapabilities()
                : $this->knmiForecastCapabilities();
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
        $knmi = app(KnmiRadarConfiguration::class);
        $lightning = app(EumetsatLightningConfiguration::class);

        $this->assertSame(7200, $knmi->frameCacheSeconds());
        $this->assertSame(3600, $knmi->maximumFallbackAgeSeconds());
        $this->assertSame(45, $knmi->timelineLockSeconds());
        $this->assertSame(35, $knmi->frameLockSeconds());
        $this->assertSame(35, $knmi->upstreamThrottleLockSeconds());
        $this->assertSame(5, $knmi->upstreamThrottleWaitSeconds());
        $this->assertSame(1050, $knmi->upstreamMinimumIntervalMilliseconds());
        $this->assertSame(50, $knmi->upstreamJitterMilliseconds());
        $this->assertSame(1_048_576, $knmi->maximumFrameBytes());
        $this->assertSame(3600, $lightning->maximumFallbackAgeSeconds());
        $this->assertSame(5400, $lightning->frameCacheSeconds());
        $this->assertSame(25, $lightning->timelineLockSeconds());
        $this->assertSame(30, $lightning->frameLockSeconds());
        $this->assertSame(262_144, $lightning->maximumFrameBytes());
    }

    public function test_frame_render_contracts_cover_the_regional_bounds_and_immutable_image_parameters(): void
    {
        $knmi = app(KnmiRadarConfiguration::class);
        $lightning = app(EumetsatLightningConfiguration::class);

        $this->assertSame([1.0, 49.0, 10.0, 55.0], $knmi->bbox());
        $this->assertSame([1.0, 49.0, 10.0, 55.0], $lightning->bbox());
        $this->assertSame([
            'srs' => 'EPSG:4326',
            'bbox' => [1.0, 49.0, 10.0, 55.0],
            'width' => 1200,
            'height' => 800,
        ], array_intersect_key($knmi->renderContract(), array_flip(['srs', 'bbox', 'width', 'height'])));
        $this->assertSame([
            'crs' => 'CRS:84',
            'bbox' => [1.0, 49.0, 10.0, 55.0],
            'width' => 960,
            'height' => 640,
        ], array_intersect_key($lightning->renderContract(), array_flip(['crs', 'bbox', 'width', 'height'])));
    }

    public function test_frame_tokens_are_bound_to_the_complete_render_contract(): void
    {
        $this->fakeCapabilities();

        $frame = app(OperationalRadarService::class)->metadata()['precipitation']['frames'][13];
        $contract = json_encode(
            app(KnmiRadarConfiguration::class)->renderContract(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        $configuredKey = (string) config('app.key');
        $rawKey = base64_decode(substr($configuredKey, 7), true);
        $this->assertIsString($rawKey);
        $tokenKey = hash_hmac('sha256', 'DIS operational radar frame tokens', $rawKey, true);
        $context = 'f20260729T163500Z';
        $digest = substr(hash_hmac(
            'sha256',
            implode('|', [
                'operational-radar-frame',
                'precipitation',
                $frame['valid_at'],
                $context,
                $contract,
            ]),
            $tokenKey,
        ), 0, 16);

        $this->assertSame(
            '20260729T164000Z-'.$context.'-'.$digest,
            $this->tokenFromUrl($frame['image_url']),
        );
    }

    public function test_knmi_render_dimensions_are_fixed_and_fail_closed_on_configuration_drift(): void
    {
        config()->set('dis.knmi_radar.frame_width', 1199);

        $this->expectException(\RuntimeException::class);
        app(KnmiRadarConfiguration::class)->renderContract();
    }

    public function test_lightning_render_dimensions_are_fixed_and_fail_closed_on_configuration_drift(): void
    {
        config()->set('dis.eumetsat_lightning.frame_height', 639);

        $this->expectException(\RuntimeException::class);
        app(EumetsatLightningConfiguration::class)->renderContract();
    }

    public function test_invalid_or_incomplete_remote_metadata_fails_closed_without_frame_urls(): void
    {
        Http::fake([
            'https://anonymous.api.dataplatform.knmi.nl/*' => Http::response('<invalid/>', 200, ['Content-Type' => 'application/xml']),
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
            return $this->capabilitiesResponse($request);
        });
    }

    private function capabilitiesResponse(Request $request): mixed
    {
        if (str_contains($request->url(), 'view.eumetsat.int')) {
            return Http::response($this->eumetsatCapabilities(), 200, ['Content-Type' => 'application/xml']);
        }

        return match ($request->data()['dataset'] ?? null) {
            'nl_rdr_data_rtcor_5m' => Http::response(
                $this->knmiObservationCapabilities(),
                200,
                ['Content-Type' => 'text/xml'],
            ),
            'radar_forecast_2.0' => Http::response(
                $this->knmiForecastCapabilities(),
                200,
                ['Content-Type' => 'text/xml'],
            ),
            default => Http::response('not found', 404, ['Content-Type' => 'text/plain']),
        };
    }

    private function knmiObservationCapabilities(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms">
  <Service><Name>WMS</Name><Title>KNMI ADAGUC WMS</Title></Service>
  <Capability><Layer><Layer>
    <Name>precipitation_real_time</Name>
    <Dimension name="time" default="2026-07-29T16:35:00.000Z" units="ISO8601">2026-07-22T16:40:00.000Z/2026-07-29T16:35:00.000Z/PT5M</Dimension>
    <Style><Name>radar/nearest</Name></Style>
    <Style><Name>rainrate-blue-to-purple/shaded</Name></Style>
  </Layer></Layer></Capability>
</WMS_Capabilities>
XML;
    }

    private function knmiForecastCapabilities(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms">
  <Service><Name>WMS</Name><Title>KNMI ADAGUC WMS</Title></Service>
  <Capability><Layer><Layer>
    <Name>precipitation_nowcast</Name>
    <Dimension name="time" default="2026-07-29T18:35:00.000Z" units="ISO8601">2026-07-22T16:40:00.000Z/2026-07-29T18:35:00.000Z/PT5M</Dimension>
    <Dimension name="reference_time" default="2026-07-29T16:35:00.000Z" units="ISO8601">2026-07-22T16:40:00.000Z/2026-07-29T16:35:00.000Z/PT5M</Dimension>
    <Style><Name>radar/nearest</Name></Style>
    <Style><Name>rainrate-blue-to-purple/shaded</Name></Style>
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
