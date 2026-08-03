<?php

namespace Tests\Feature;

use App\Services\GeocodingService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class GeocodingServiceTest extends TestCase
{
    public function test_nominatim_primary_returns_an_exact_pair_with_bounded_transport_options(): void
    {
        $this->configure('nominatim');
        $guarded = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) use (&$guarded) {
            $guarded = str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search?')
                && $request['q'] === 'Utrecht Centraal, Nederland'
                && $request['format'] === 'jsonv2'
                && (int) $request['limit'] === 1
                && $request['countrycodes'] === 'nl,be,de'
                && $request->hasHeader('User-Agent', 'D.I.S Geocoder Test')
                && $request->hasHeader('Accept-Encoding', 'identity')
                && ($options['allow_redirects'] ?? null) === false
                && ($options['decode_content'] ?? null) === false
                && ($options['connect_timeout'] ?? null) === 2
                && ($options['timeout'] ?? null) === 5
                && is_callable($options['on_headers'] ?? null)
                && is_callable($options['progress'] ?? null);

            return Http::response([[
                'lat' => '52.09070004',
                'lon' => '5.12140004',
            ]]);
        });

        self::assertSame([
            'latitude' => '52.0907000',
            'longitude' => '5.1214000',
        ], app(GeocodingService::class)->coordinatesFor('  Utrecht Centraal, Nederland  '));
        self::assertTrue($guarded);
        Http::assertSentCount(1);
    }

    public function test_nominatim_failure_falls_back_to_a_matching_pdok_free_search_result(): void
    {
        $this->configure('nominatim');
        $hosts = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$hosts) {
            $hosts[] = parse_url($request->url(), PHP_URL_HOST);

            return str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search?')
                ? Http::response(['error' => 'rate limited'], 429)
                : Http::response([
                    'response' => [
                        'docs' => [[
                            'centroide_ll' => 'POINT(5.12140004 52.09070004)',
                            'weergavenaam' => 'Domplein 1, 3512JC Utrecht',
                        ]],
                    ],
                ]);
        });

        self::assertSame([
            'latitude' => '52.0907000',
            'longitude' => '5.1214000',
        ], app(GeocodingService::class)->coordinatesFor('Domplein 1, Utrecht'));
        self::assertSame(['nominatim.openstreetmap.org', 'api.pdok.nl'], $hosts);
        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?',
        ) && $request['q'] === '"Domplein 1, Utrecht"' && (int) $request['rows'] === 1);
    }

    #[DataProvider('mismatchedPdokResults')]
    public function test_pdok_never_accepts_a_semantically_unrelated_first_result(
        string $query,
        string $displayLabel,
    ): void {
        $this->configure('nominatim');
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([], 503),
            'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free*' => Http::response([
                'response' => ['docs' => [[
                    'centroide_ll' => 'POINT(5.6900000 51.4800000)',
                    'weergavenaam' => $displayLabel,
                ]]],
            ]),
        ]);

        self::assertNull(app(GeocodingService::class)->coordinatesFor($query));
        Http::assertSentCount(2);
    }

    /** @return iterable<string, array{string, string}> */
    public static function mismatchedPdokResults(): iterable
    {
        yield 'foreign address mapped into the Netherlands' => [
            'Rue de la Loi 16, Bruxelles',
            'Parc Bruxelles, Helmond',
        ];
        yield 'operational place mapped to a municipality centre' => [
            'Brandweerkazerne Utrecht',
            'Gemeente Utrecht',
        ];
        yield 'nonexistent numbered address mapped by shared words' => [
            'Dit Bestaat Echt Niet 999, Utrecht',
            'Vergeet mij niet, Wijk en Aalburg',
        ];
        yield 'same street and house number in another locality' => [
            'Dorpsstraat 999, Utrecht',
            'Dorpsstraat 999, 1566JD Assendelft',
        ];
        yield 'longer street name in the same locality' => [
            'Kerkstraat 1A-BS, Utrecht',
            'Oude Kerkstraat 1A-BS, 3572TG Utrecht',
        ];
        yield 'same label with a conflicting supplied postcode' => [
            'Domplein 1, 1234AB Utrecht',
            'Domplein 1, 3512JC Utrecht',
        ];
        yield 'extra conflicting postcode hidden by normalization' => [
            'Domplein 1, 3512JC 9999ZZ Utrecht',
            'Domplein 1, 3512JC Utrecht',
        ];
    }

    public function test_pdok_can_be_primary_and_no_match_falls_back_to_nominatim(): void
    {
        $this->configure('pdok');
        $hosts = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$hosts) {
            $hosts[] = parse_url($request->url(), PHP_URL_HOST);

            return str_starts_with($request->url(), 'https://api.pdok.nl/')
                ? Http::response(['response' => ['docs' => []]])
                : Http::response([[
                    'lat' => '50.8503000',
                    'lon' => '4.3517000',
                ]]);
        });

        self::assertSame([
            'latitude' => '50.8503000',
            'longitude' => '4.3517000',
        ], app(GeocodingService::class)->coordinatesFor('Brussel, Belgie'));
        self::assertSame(['api.pdok.nl', 'nominatim.openstreetmap.org'], $hosts);
        Http::assertSentCount(2);
    }

    #[DataProvider('unsafePrimaryUrls')]
    public function test_unsafe_configured_primary_url_is_never_requested(
        string $provider,
        string $configurationKey,
        string $unsafeUrl,
        string $expectedFallbackHost,
    ): void {
        $this->configure($provider);
        config()->set('dis.geocoding.'.$configurationKey, $unsafeUrl);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($expectedFallbackHost) {
            self::assertSame($expectedFallbackHost, parse_url($request->url(), PHP_URL_HOST));

            return $expectedFallbackHost === 'api.pdok.nl'
                ? Http::response(['response' => ['docs' => [[
                    'centroide_ll' => 'POINT(5.1 52.1)',
                    'weergavenaam' => 'Veilige fallbacklocatie',
                ]]]])
                : Http::response([['lat' => '52.1', 'lon' => '5.1']]);
        });

        self::assertSame([
            'latitude' => '52.1000000',
            'longitude' => '5.1000000',
        ], app(GeocodingService::class)->coordinatesFor('Veilige fallbacklocatie'));
        Http::assertSentCount(1);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function unsafePrimaryUrls(): iterable
    {
        yield 'nominatim plaintext' => [
            'nominatim',
            'nominatim_url',
            'http://nominatim.openstreetmap.org/search',
            'api.pdok.nl',
        ];
        yield 'nominatim userinfo' => [
            'nominatim',
            'nominatim_url',
            'https://user:secret@nominatim.openstreetmap.org/search',
            'api.pdok.nl',
        ];
        yield 'nominatim query injection' => [
            'nominatim',
            'nominatim_url',
            'https://nominatim.openstreetmap.org/search?target=internal',
            'api.pdok.nl',
        ];
        yield 'nominatim fragment' => [
            'nominatim',
            'nominatim_url',
            'https://nominatim.openstreetmap.org/search#internal',
            'api.pdok.nl',
        ];
        yield 'nominatim wrong host' => [
            'nominatim',
            'nominatim_url',
            'https://example.test/search',
            'api.pdok.nl',
        ];
        yield 'nominatim wrong port' => [
            'nominatim',
            'nominatim_url',
            'https://nominatim.openstreetmap.org:8443/search',
            'api.pdok.nl',
        ];
        yield 'pdok wrong host' => [
            'pdok',
            'pdok_url',
            'https://example.test/bzk/locatieserver/search/v3_1/free',
            'nominatim.openstreetmap.org',
        ];
    }

    public function test_incomplete_and_out_of_range_provider_pairs_are_rejected(): void
    {
        $this->configure('nominatim');
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([[
                'lat' => '52.0907',
            ]]),
            'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free*' => Http::response([
                'response' => ['docs' => [[
                    'centroide_ll' => 'POINT(181 91)',
                    'weergavenaam' => 'Ongeldige locatie',
                ]]],
            ]),
        ]);

        self::assertNull(app(GeocodingService::class)->coordinatesFor('Ongeldige locatie'));
        Http::assertSentCount(2);
    }

    public function test_oversized_or_malformed_primary_response_falls_back_without_exposing_the_query(): void
    {
        $this->configure('nominatim');
        Http::preventStrayRequests();
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response(
                str_repeat('x', GeocodingService::MAX_RESPONSE_BYTES + 1),
            ),
            'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free*' => Http::response([
                'response' => ['docs' => [[
                    'centroide_ll' => 'POINT(4.3517 50.8503)',
                    'weergavenaam' => 'Vertrouwelijk inzetadres',
                ]]],
            ]),
        ]);

        self::assertSame([
            'latitude' => '50.8503000',
            'longitude' => '4.3517000',
        ], app(GeocodingService::class)->coordinatesFor('Vertrouwelijk inzetadres'));
        Http::assertSentCount(2);
    }

    public function test_encoded_responses_and_oversized_transfers_are_guarded(): void
    {
        $method = new \ReflectionMethod(GeocodingService::class, 'boundedHttpOptions');
        $options = $method->invoke(app(GeocodingService::class));

        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['decode_content']);
        self::assertIsCallable($options['on_headers']);
        self::assertIsCallable($options['progress']);

        try {
            $options['on_headers'](new PsrResponse(200, ['Content-Encoding' => 'gzip']));
            self::fail('Encoded geocoding responses must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Geocoding provider returned an encoded response.', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $options['progress'](0, GeocodingService::MAX_RESPONSE_BYTES + 1, 0, 0);
    }

    public function test_disabled_blank_or_unsupported_geocoding_never_calls_a_provider(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config()->set('dis.geocoding.enabled', false);
        self::assertNull(app(GeocodingService::class)->coordinatesFor('Utrecht'));

        config()->set('dis.geocoding.enabled', true);
        self::assertNull(app(GeocodingService::class)->coordinatesFor('   '));
        config()->set('dis.geocoding.provider', 'unsupported');
        self::assertNull(app(GeocodingService::class)->coordinatesFor('Utrecht'));

        Http::assertSentCount(0);
    }

    private function configure(string $provider): void
    {
        config()->set([
            'dis.geocoding.enabled' => true,
            'dis.geocoding.provider' => $provider,
            'dis.geocoding.nominatim_url' => 'https://nominatim.openstreetmap.org/search',
            'dis.geocoding.pdok_url' => 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free',
            'dis.geocoding.user_agent' => 'D.I.S Geocoder Test',
            'dis.geocoding.country_codes' => 'nl,be,de',
            'dis.geocoding.connect_timeout_seconds' => 2,
            'dis.geocoding.timeout_seconds' => 5,
        ]);
    }
}
