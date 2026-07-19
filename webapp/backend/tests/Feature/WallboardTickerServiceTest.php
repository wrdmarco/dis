<?php

namespace Tests\Feature;

use App\Services\WallboardTickerService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardTickerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'dis.wallboards.ticker_connect_timeout_seconds' => 1,
            'dis.wallboards.ticker_timeout_seconds' => 2,
            'dis.wallboards.ticker_cache_seconds' => 300,
            'dis.wallboards.ticker_failure_cache_seconds' => 60,
        ]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_internal_sources_return_only_bounded_plain_text_when_enabled(): void
    {
        $service = $this->service();

        $items = $service->items([
            'enabled' => true,
            'sources' => [
                [
                    'id' => 'dispatch-note',
                    'type' => 'internal',
                    'label' => 'Meldkamer',
                    'text' => "  <strong>Windwaarschuwing</strong>\nvoor de kust  ",
                ],
                [
                    'id' => 'invalid source id',
                    'type' => 'internal',
                    'label' => 'Ongeldig',
                    'text' => 'Wordt overgeslagen',
                ],
            ],
        ]);

        $this->assertSame([[
            'source_id' => 'dispatch-note',
            'source_type' => 'internal',
            'source_label' => 'Meldkamer',
            'text' => 'Windwaarschuwing voor de kust',
        ]], $items);
        Http::assertNothingSent();
    }

    public function test_disabled_ticker_does_not_resolve_or_fetch_sources(): void
    {
        $resolved = false;
        $service = $this->service(function () use (&$resolved): array {
            $resolved = true;

            return ['1.1.1.1'];
        });

        $this->assertSame([], $service->items([
            'enabled' => false,
            'sources' => [$this->rssSource()],
        ]));
        $this->assertFalse($resolved);
        Http::assertNothingSent();
    }

    public function test_rss_and_atom_titles_are_plain_bounded_deduplicated_and_cached(): void
    {
        Http::fake([
            'https://feeds.example.org/news.xml' => Http::response(
                '<?xml version="1.0"?><rss><channel>'
                .'<item><title> Eerste &amp; belangrijkste </title></item>'
                .'<item><title><![CDATA[<b>Tweede bericht</b>]]></title></item>'
                .'<item><title>Eerste &amp; belangrijkste</title></item>'
                .'</channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml; charset=UTF-8'],
            ),
        ]);
        $service = $this->service();
        $configuration = ['enabled' => true, 'sources' => [$this->rssSource()]];

        $expected = [
            [
                'source_id' => 'news',
                'source_type' => 'rss',
                'source_label' => 'Nieuws',
                'text' => 'Eerste & belangrijkste',
            ],
            [
                'source_id' => 'news',
                'source_type' => 'rss',
                'source_label' => 'Nieuws',
                'text' => 'Tweede bericht',
            ],
        ];

        $this->assertSame($expected, $service->items($configuration));
        $this->assertSame($expected, $service->items($configuration));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://feeds.example.org/news.xml'
            && $request->hasHeader('Accept-Encoding', 'identity'));
    }

    public function test_each_resolution_fetches_at_most_one_cold_feed_while_returning_all_cached_feeds(): void
    {
        Http::fake([
            'https://feeds.example.org/first.xml' => Http::response(
                '<rss><channel><item><title>Eerste bron</title></item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
            'https://feeds.example.org/second.xml' => Http::response(
                '<rss><channel><item><title>Tweede bron</title></item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ]);
        $service = $this->service();
        $configuration = [
            'enabled' => true,
            'sources' => [
                [
                    'id' => 'first',
                    'type' => 'rss',
                    'label' => 'Eerste',
                    'url' => 'https://feeds.example.org/first.xml',
                ],
                [
                    'id' => 'second',
                    'type' => 'rss',
                    'label' => 'Tweede',
                    'url' => 'https://feeds.example.org/second.xml',
                ],
            ],
        ];

        $this->assertSame(['Eerste bron'], collect($service->items($configuration))->pluck('text')->all());
        Http::assertSentCount(1);

        $this->assertSame(
            ['Eerste bron', 'Tweede bron'],
            collect($service->items($configuration))->pluck('text')->all(),
        );
        Http::assertSentCount(2);

        $this->assertSame(
            ['Eerste bron', 'Tweede bron'],
            collect($service->items($configuration))->pluck('text')->all(),
        );
        Http::assertSentCount(2);
    }

    public function test_failure_cached_cold_feed_allows_the_next_feed_to_warm_on_the_next_resolution(): void
    {
        Http::fake([
            'https://feeds.example.org/failing.xml' => Http::failedConnection('transport detail'),
            'https://feeds.example.org/next.xml' => Http::response(
                '<rss><channel><item><title>Volgende bron</title></item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ]);
        $service = $this->service();
        $configuration = [
            'enabled' => true,
            'sources' => [
                [
                    'id' => 'failing',
                    'type' => 'rss',
                    'label' => 'Uitval',
                    'url' => 'https://feeds.example.org/failing.xml',
                ],
                [
                    'id' => 'next',
                    'type' => 'rss',
                    'label' => 'Volgende',
                    'url' => 'https://feeds.example.org/next.xml',
                ],
            ],
        ];

        $this->assertSame([], $service->items($configuration));
        Http::assertSentCount(1);

        $this->assertSame(['Volgende bron'], collect($service->items($configuration))->pluck('text')->all());
        Http::assertSentCount(2);

        $this->assertSame(['Volgende bron'], collect($service->items($configuration))->pluck('text')->all());
        Http::assertSentCount(2);
    }

    public function test_atom_summary_is_used_when_an_entry_has_no_title(): void
    {
        Http::fake([
            '*' => Http::response(
                '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">'
                .'<entry><summary><![CDATA[<p>Verwachting: windkracht 6</p>]]></summary></entry>'
                .'</feed>',
                200,
                ['Content-Type' => 'application/atom+xml'],
            ),
        ]);

        $items = $this->service()->items(['enabled' => true, 'sources' => [$this->rssSource()]]);

        $this->assertSame('Verwachting: windkracht 6', $items[0]['text'] ?? null);
    }

    #[DataProvider('unsafeTargetProvider')]
    public function test_private_reserved_and_mixed_dns_answers_are_rejected(array $addresses): void
    {
        Http::fake(['*' => Http::response('<rss/>', 200, ['Content-Type' => 'application/rss+xml'])]);

        $items = $this->service(fn (): array => $addresses)
            ->items(['enabled' => true, 'sources' => [$this->rssSource()]]);

        $this->assertSame([], $items);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function unsafeTargetProvider(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'private IPv4' => [['10.0.0.15']];
        yield 'link local metadata' => [['169.254.169.254']];
        yield 'carrier NAT' => [['100.64.0.1']];
        yield 'IPv6 loopback' => [['::1']];
        yield 'IPv6 unique local' => [['fd00::10']];
        yield 'documentation address' => [['203.0.113.10']];
        yield 'mixed public and private' => [['1.1.1.1', '10.10.10.10']];
        yield 'no DNS answer' => [[]];
    }

    public function test_private_literal_ip_is_rejected_without_a_dns_lookup(): void
    {
        $resolved = false;
        Http::fake(['*' => Http::response('<rss/>', 200, ['Content-Type' => 'application/rss+xml'])]);
        $source = $this->rssSource('https://127.0.0.1/feed.xml');

        $items = $this->service(function () use (&$resolved): array {
            $resolved = true;

            return ['1.1.1.1'];
        })->items(['enabled' => true, 'sources' => [$source]]);

        $this->assertSame([], $items);
        $this->assertFalse($resolved);
        Http::assertNothingSent();
    }

    public function test_redirects_are_not_followed_or_cached_as_success(): void
    {
        Http::fake([
            'https://feeds.example.org/news.xml' => Http::response('', 302, [
                'Location' => 'https://127.0.0.1/internal.xml',
            ]),
        ]);
        $service = $this->service();
        $configuration = ['enabled' => true, 'sources' => [$this->rssSource()]];

        $this->assertSame([], $service->items($configuration));
        $this->assertSame([], $service->items($configuration));
        Http::assertSentCount(1);
    }

    public function test_doctype_entities_html_responses_and_oversized_bodies_fail_closed(): void
    {
        $responses = [
            Http::response(
                '<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&xxe;</title></item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
            Http::response('<html><body>Not a feed</body></html>', 200, ['Content-Type' => 'text/html']),
            Http::response(
                str_repeat('x', WallboardTickerService::MAX_RESPONSE_BYTES + 1),
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ];
        $request = 0;
        Http::fake(function () use (&$request, $responses) {
            return $responses[$request++];
        });

        foreach (['xxe', 'html', 'large'] as $id) {
            $source = $this->rssSource("https://feeds.example.org/{$id}.xml");
            $this->assertSame([], $this->service()->items(['enabled' => true, 'sources' => [$source]]));
        }

        Http::assertSentCount(3);
    }

    public function test_feed_item_and_global_limits_are_enforced(): void
    {
        $feedItems = '';
        for ($index = 1; $index <= 20; $index++) {
            $feedItems .= '<item><title>'.str_repeat((string) ($index % 10), 350).'</title></item>';
        }
        Http::fake(['*' => Http::response(
            "<rss><channel>{$feedItems}</channel></rss>",
            200,
            ['Content-Type' => 'application/rss+xml'],
        )]);

        $items = $this->service()->items(['enabled' => true, 'sources' => [$this->rssSource()]]);

        $this->assertCount(WallboardTickerService::MAX_RSS_ITEMS_PER_SOURCE, $items);
        foreach ($items as $item) {
            $this->assertLessThanOrEqual(WallboardTickerService::MAX_RSS_TEXT_LENGTH, mb_strlen($item['text']));
        }
    }

    #[DataProvider('invalidUrlProvider')]
    public function test_only_plain_https_urls_on_port_443_are_accepted_by_the_contract(string $url): void
    {
        $this->assertFalse(WallboardTickerService::hasValidHttpsUrlSyntax($url));
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidUrlProvider(): iterable
    {
        yield 'http' => ['http://feeds.example.org/rss'];
        yield 'credentials' => ['https://user:secret@feeds.example.org/rss'];
        yield 'custom port' => ['https://feeds.example.org:8443/rss'];
        yield 'fragment' => ['https://feeds.example.org/rss#private'];
        yield 'trailing dot' => ['https://feeds.example.org./rss'];
        yield 'encoded hostname' => ['https://feeds%2eexample.org/rss'];
        yield 'integer IPv4' => ['https://2130706433/rss'];
        yield 'control character' => ["https://feeds.example.org/rss\nX-Test: true"];
    }

    public function test_network_failure_returns_no_details_and_is_failure_cached(): void
    {
        Http::fake(['*' => Http::failedConnection('secret transport detail')]);
        $service = $this->service();
        $configuration = ['enabled' => true, 'sources' => [$this->rssSource()]];

        $this->assertSame([], $service->items($configuration));
        $this->assertSame([], $service->items($configuration));
        Http::assertSentCount(1);
    }

    private function service(?callable $resolver = null): WallboardTickerService
    {
        $resolver ??= static fn (string $host): array => $host === 'feeds.example.org' ? ['1.1.1.1'] : [];

        return new WallboardTickerService($resolver(...));
    }

    /** @return array{id: string, type: string, label: string, url: string} */
    private function rssSource(string $url = 'https://feeds.example.org/news.xml'): array
    {
        return [
            'id' => 'news',
            'type' => 'rss',
            'label' => 'Nieuws',
            'url' => $url,
        ];
    }
}
