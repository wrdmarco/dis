<?php

namespace Tests\Feature;

use App\Services\KnmiRadarWmsClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

final class KnmiRadarWmsClientTest extends TestCase
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

    public function test_combines_exact_observation_and_forecast_capabilities_into_37_frames(): void
    {
        $requestStarts = [];
        Http::fake(function (Request $request) use (&$requestStarts) {
            $requestStarts[] = microtime(true);

            return $this->capabilitiesResponse($request);
        });

        $timeline = app(KnmiRadarWmsClient::class)->timeline();

        $this->assertSame('2026-07-29T16:35:00+00:00', $timeline['reference_time']);
        $this->assertCount(37, $timeline['frames']);
        $this->assertSame([
            'valid_at' => '2026-07-29T15:35:00+00:00',
            'phase' => 'observation',
        ], $timeline['frames'][0]);
        $this->assertSame([
            'valid_at' => '2026-07-29T16:35:00+00:00',
            'phase' => 'observation',
        ], $timeline['frames'][12]);
        $this->assertSame([
            'valid_at' => '2026-07-29T16:40:00+00:00',
            'phase' => 'forecast',
        ], $timeline['frames'][13]);
        $this->assertSame([
            'valid_at' => '2026-07-29T18:35:00+00:00',
            'phase' => 'forecast',
        ], $timeline['frames'][36]);
        $this->assertCount(2, $requestStarts);
        $this->assertGreaterThanOrEqual(1.0, $requestStarts[1] - $requestStarts[0]);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'dataset' => 'nl_rdr_data_rtcor_5m',
            'service' => 'WMS',
            'version' => '1.3.0',
            'request' => 'GetCapabilities',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'dataset' => 'radar_forecast_2.0',
            'service' => 'WMS',
            'version' => '1.3.0',
            'request' => 'GetCapabilities',
        ]);
    }

    public function test_get_map_uses_phase_specific_fixed_datasets_and_a_global_one_second_gate(): void
    {
        $png = $this->png(960, 580);
        $requestStarts = [];
        Http::fake(function (Request $request) use ($png, &$requestStarts) {
            $requestStarts[] = microtime(true);

            return Http::response($png, 200, ['Content-Type' => 'image/png']);
        });

        $client = app(KnmiRadarWmsClient::class);
        $reference = CarbonImmutable::parse('2026-07-29T16:35:00Z');
        $this->assertSame(
            $png,
            $client->frame($reference, $reference, 'observation'),
        );
        $this->assertSame(
            $png,
            $client->frame($reference->addMinutes(5), $reference, 'forecast'),
        );

        $this->assertCount(2, $requestStarts);
        $this->assertGreaterThanOrEqual(1.0, $requestStarts[1] - $requestStarts[0]);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $query = $request->data();

            return $query === [
                'dataset' => 'nl_rdr_data_rtcor_5m',
                'service' => 'WMS',
                'version' => '1.1.1',
                'request' => 'GetMap',
                'layers' => 'precipitation_real_time',
                'styles' => 'rainrate-blue-to-purple/shaded',
                'srs' => 'EPSG:4326',
                'bbox' => '2.5,50.5,7.8,53.7',
                'width' => 960,
                'height' => 580,
                'format' => 'image/png',
                'transparent' => 'true',
                'time' => '2026-07-29T16:35:00Z',
            ];
        });
        Http::assertSent(function (Request $request): bool {
            $query = $request->data();

            return $query === [
                'dataset' => 'radar_forecast_2.0',
                'service' => 'WMS',
                'version' => '1.1.1',
                'request' => 'GetMap',
                'layers' => 'precipitation_nowcast',
                'styles' => 'rainrate-blue-to-purple/shaded',
                'srs' => 'EPSG:4326',
                'bbox' => '2.5,50.5,7.8,53.7',
                'width' => 960,
                'height' => 580,
                'format' => 'image/png',
                'transparent' => 'true',
                'time' => '2026-07-29T16:40:00Z',
                'reference_time' => '2026-07-29T16:35:00Z',
            ];
        });
    }

    public function test_observation_default_may_lead_the_forecast_reference_when_coverage_is_complete(): void
    {
        $observation = preg_replace(
            '/default="2026-07-29T16:35:00\.000Z"/',
            'default="2026-07-29T16:40:00.000Z"',
            $this->observationCapabilities(),
            1,
        );
        $this->assertIsString($observation);
        $observation = str_replace(
            '2026-07-29T16:35:00.000Z/PT5M',
            '2026-07-29T16:40:00.000Z/PT5M',
            $observation,
        );
        Http::fake(function (Request $request) use ($observation) {
            return Http::response(
                ($request->data()['dataset'] ?? null) === 'nl_rdr_data_rtcor_5m'
                    ? $observation
                    : $this->forecastCapabilities(),
                200,
                ['Content-Type' => 'text/xml'],
            );
        });

        $timeline = app(KnmiRadarWmsClient::class)->timeline();

        $this->assertSame('2026-07-29T16:35:00+00:00', $timeline['reference_time']);
        $this->assertCount(37, $timeline['frames']);
    }

    public function test_missing_defaults_mismatched_reference_and_incomplete_coverage_are_rejected(): void
    {
        $invalidPairs = [
            [
                str_replace(
                    ' default="2026-07-29T16:35:00.000Z"',
                    '',
                    $this->observationCapabilities(),
                ),
                $this->forecastCapabilities(),
            ],
            [
                $this->observationCapabilities(),
                str_replace(
                    'default="2026-07-29T16:35:00.000Z"',
                    'default="2026-07-29T16:20:00.000Z"',
                    $this->forecastCapabilities(),
                ),
            ],
            [
                str_replace(
                    '2026-07-22T16:40:00.000Z/2026-07-29T16:35:00.000Z',
                    '2026-07-29T15:40:00.000Z/2026-07-29T16:35:00.000Z',
                    $this->observationCapabilities(),
                ),
                $this->forecastCapabilities(),
            ],
        ];

        foreach ($invalidPairs as [$observation, $forecast]) {
            Cache::flush();
            Http::fake(function (Request $request) use ($observation, $forecast) {
                return Http::response(
                    ($request->data()['dataset'] ?? null) === 'nl_rdr_data_rtcor_5m'
                        ? $observation
                        : $forecast,
                    200,
                    ['Content-Type' => 'text/xml'],
                );
            });

            try {
                app(KnmiRadarWmsClient::class)->timeline();
                $this->fail('Invalid KNMI capabilities were accepted.');
            } catch (Throwable $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_wrong_content_type_and_wrong_png_dimensions_are_rejected(): void
    {
        $reference = CarbonImmutable::parse('2026-07-29T16:35:00Z');
        Http::fake([
            '*' => Http::response('not an image', 200, ['Content-Type' => 'text/plain']),
        ]);
        try {
            app(KnmiRadarWmsClient::class)->frame($reference, $reference, 'observation');
            $this->fail('A non-PNG KNMI response was accepted.');
        } catch (Throwable $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        Cache::flush();
        Http::fake([
            '*' => Http::response($this->png(959, 580), 200, ['Content-Type' => 'image/png']),
        ]);
        try {
            app(KnmiRadarWmsClient::class)->frame($reference, $reference, 'observation');
            $this->fail('A KNMI frame with invalid dimensions was accepted.');
        } catch (Throwable $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function test_invalid_shared_throttle_state_fails_closed_without_an_upstream_request(): void
    {
        Cache::put(
            'operational-radar:knmi:wms-throttle:v1:last-start-ms',
            'invalid',
            60,
        );
        Http::fake();
        $reference = CarbonImmutable::parse('2026-07-29T16:35:00Z');

        try {
            app(KnmiRadarWmsClient::class)->frame($reference, $reference, 'observation');
            $this->fail('An invalid KNMI throttle state was ignored.');
        } catch (Throwable $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_numeric_redis_throttle_state_is_accepted(): void
    {
        Cache::put(
            'operational-radar:knmi:wms-throttle:v1:last-start-ms',
            (string) ((int) floor(microtime(true) * 1_000) - 2_000),
            60,
        );
        $png = $this->png(960, 580);
        Http::fake([
            '*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $reference = CarbonImmutable::parse('2026-07-29T16:35:00Z');

        $this->assertSame(
            $png,
            app(KnmiRadarWmsClient::class)->frame($reference, $reference, 'observation'),
        );
        Http::assertSentCount(1);
    }

    private function capabilitiesResponse(Request $request): mixed
    {
        return Http::response(
            ($request->data()['dataset'] ?? null) === 'nl_rdr_data_rtcor_5m'
                ? $this->observationCapabilities()
                : $this->forecastCapabilities(),
            200,
            ['Content-Type' => 'text/xml'],
        );
    }

    private function observationCapabilities(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms">
  <Service><Name>WMS</Name><Title>KNMI ADAGUC WMS</Title></Service>
  <Capability><Layer><Layer>
    <Name>precipitation_real_time</Name>
    <Dimension name="time" default="2026-07-29T16:35:00.000Z" units="ISO8601">2026-07-22T16:40:00.000Z/2026-07-29T16:35:00.000Z/PT5M</Dimension>
    <Style><Name>rainrate-blue-to-purple/shaded</Name></Style>
  </Layer></Layer></Capability>
</WMS_Capabilities>
XML;
    }

    private function forecastCapabilities(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms">
  <Service><Name>WMS</Name><Title>KNMI ADAGUC WMS</Title></Service>
  <Capability><Layer><Layer>
    <Name>precipitation_nowcast</Name>
    <Dimension name="time" default="2026-07-29T18:35:00.000Z" units="ISO8601">2026-07-22T16:40:00.000Z/2026-07-29T18:35:00.000Z/PT5M</Dimension>
    <Dimension name="reference_time" default="2026-07-29T16:35:00.000Z" units="ISO8601">2026-07-22T16:40:00.000Z/2026-07-29T16:35:00.000Z/PT5M</Dimension>
    <Style><Name>rainrate-blue-to-purple/shaded</Name></Style>
  </Layer></Layer></Capability>
</WMS_Capabilities>
XML;
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
