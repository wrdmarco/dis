<?php

namespace Tests\Feature;

use App\Services\DwdMosmixLowCloudService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DwdMosmixLowCloudServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-31T18:30:00Z');
        Cache::flush();
        config([
            'dis.wallboards.uav_forecast.dwd_mosmix_cache_seconds' => 900,
            'dis.wallboards.uav_forecast.dwd_mosmix_last_good_cache_seconds' => 21600,
            'dis.wallboards.uav_forecast.dwd_mosmix_model_stale_seconds' => 43200,
            'dis.wallboards.uav_forecast.dwd_mosmix_retry_delay_ms' => 50,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_reads_nl_and_n05_in_memory_and_reuses_the_shared_cache(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::stationUrl('06260') => Http::response(
                $this->kmz('06260', [10, 72, 20], [0, 35, 5]),
                200,
                ['Content-Type' => 'application/vnd.google-earth.kmz'],
            ),
        ]);
        $filesBefore = Storage::disk('local')->allFiles();

        $service = app(DwdMosmixLowCloudService::class);
        $readings = $service->forStations(
            ['06260'],
            CarbonImmutable::parse('2026-07-31T19:00:00Z'),
        );

        $this->assertSame(['06260'], array_keys($readings));
        $this->assertSame('06260', $readings['06260']['station_id']);
        $this->assertSame('2026-07-31T15:00:00+00:00', $readings['06260']['model_run_at']);
        $this->assertSame('2026-07-31T19:00:00+00:00', $readings['06260']['valid_at']);
        $this->assertSame(72.0, $readings['06260']['cloud_cover_low_pct']);
        $this->assertSame(35.0, $readings['06260']['cloud_cover_below_500ft_pct']);
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::stationUrl('06260')
                && $request->header('Accept')[0] === 'application/vnd.google-earth.kmz'
                && $request->header('Accept-Encoding')[0] === 'identity';
        });
        Http::assertSentCount(1);

        $cached = $service->forStations(
            ['06260'],
            CarbonImmutable::parse('2026-07-31T19:00:00Z'),
        );
        $this->assertSame($readings, $cached);
        Http::assertSentCount(1);
    }

    public function test_it_accepts_an_alphanumeric_dwd_station_identifier(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::stationUrl('E5305') => Http::response(
                $this->kmz('E5305', [1, 2, 3], [4, 5, 6]),
                200,
                ['Content-Type' => 'application/vnd.google-earth.kmz'],
            ),
        ]);

        $readings = app(DwdMosmixLowCloudService::class)->forStations(
            ['E5305'],
            CarbonImmutable::parse('2026-07-31T20:00:00Z'),
        );

        $this->assertSame(3.0, $readings['E5305']['cloud_cover_low_pct']);
        $this->assertSame(6.0, $readings['E5305']['cloud_cover_below_500ft_pct']);
    }

    public function test_a_corrupt_or_incomplete_archive_fails_closed_without_a_file(): void
    {
        $corrupt = $this->kmz('06260', [10, 20, 30], [0, 5, 10]);
        $corrupt[80] = chr(ord($corrupt[80]) ^ 0xFF);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push($corrupt, 200, ['Content-Type' => 'application/vnd.google-earth.kmz'])
            ->push(
                $this->kmz('06260', [10, 20, 30], [0, 5]),
                200,
                ['Content-Type' => 'application/vnd.google-earth.kmz'],
            );
        $filesBefore = Storage::disk('local')->allFiles();
        $service = app(DwdMosmixLowCloudService::class);
        $validAt = CarbonImmutable::parse('2026-07-31T19:00:00Z');

        $this->assertSame([], $service->forStations(['06260'], $validAt));
        Cache::flush();
        $this->assertSame([], $service->forStations(['06260'], $validAt));
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles());
    }

    public function test_invalid_station_input_never_reaches_the_network(): void
    {
        Http::preventStrayRequests();

        $readings = app(DwdMosmixLowCloudService::class)->forStations(
            ['../../etc/passwd'],
            CarbonImmutable::parse('2026-07-31T19:00:00Z'),
        );

        $this->assertSame([], $readings);
        Http::assertNothingSent();
    }

    private static function stationUrl(string $stationId): string
    {
        return 'https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/'
            .$stationId.'/kml/MOSMIX_L_LATEST_'.$stationId.'.kmz';
    }

    /**
     * @param  list<int|float|string>  $lowValues
     * @param  list<int|float|string>  $below500Values
     */
    private function kmz(string $stationId, array $lowValues, array $below500Values): string
    {
        $times = [
            '2026-07-31T18:00:00.000Z',
            '2026-07-31T19:00:00.000Z',
            '2026-07-31T20:00:00.000Z',
        ];
        $timeXml = implode('', array_map(
            static fn (string $time): string => '<dwd:TimeStep>'.$time.'</dwd:TimeStep>',
            $times,
        ));
        $kml = '<?xml version="1.0" encoding="ISO-8859-1"?>'
            .'<kml:kml xmlns:kml="http://www.opengis.net/kml/2.2" '
            .'xmlns:dwd="https://opendata.dwd.de/weather/lib/pointforecast_dwd_extension_V1_0.xsd">'
            .'<kml:Document><kml:ExtendedData><dwd:ProductDefinition>'
            .'<dwd:Issuer>Deutscher Wetterdienst</dwd:Issuer>'
            .'<dwd:ProductID>MOSMIX</dwd:ProductID>'
            .'<dwd:IssueTime>2026-07-31T15:00:00.000Z</dwd:IssueTime>'
            .'<dwd:ForecastTimeSteps>'.$timeXml.'</dwd:ForecastTimeSteps>'
            .'</dwd:ProductDefinition></kml:ExtendedData>'
            .'<kml:Placemark><kml:name>'.$stationId.'</kml:name><kml:ExtendedData>'
            .'<dwd:Forecast dwd:elementName="Nl"><dwd:value>'
            .implode(' ', $lowValues).'</dwd:value></dwd:Forecast>'
            .'<dwd:Forecast dwd:elementName="N05"><dwd:value>'
            .implode(' ', $below500Values).'</dwd:value></dwd:Forecast>'
            .'</kml:ExtendedData></kml:Placemark></kml:Document></kml:kml>';

        return $this->archive('MOSMIX_L_2026073115_'.$stationId.'.kml', $kml);
    }

    private function archive(string $name, string $contents): string
    {
        $compressed = gzdeflate($contents, 9);
        if (! is_string($compressed)) {
            throw new \RuntimeException('Unable to create test KMZ.');
        }
        $flags = 0x0808;
        $crc = (int) hexdec(hash('crc32b', $contents));
        $local = pack(
            'VvvvvvVVVvv',
            0x04034B50,
            20,
            $flags,
            8,
            0,
            0,
            0,
            0,
            0,
            strlen($name),
            0,
        ).$name.$compressed;
        $descriptor = pack(
            'VVVV',
            0x08074B50,
            $crc,
            strlen($compressed),
            strlen($contents),
        );
        $central = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014B50,
            20,
            20,
            $flags,
            8,
            0,
            0,
            $crc,
            strlen($compressed),
            strlen($contents),
            strlen($name),
            0,
            0,
            0,
            0,
            0,
            0,
        ).$name;
        $eocd = pack(
            'VvvvvVVv',
            0x06054B50,
            0,
            0,
            1,
            1,
            strlen($central),
            strlen($local.$descriptor),
            0,
        );

        return $local.$descriptor.$central.$eocd;
    }
}
