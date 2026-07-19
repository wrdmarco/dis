<?php

namespace Tests\Feature;

use App\Services\WallboardNewsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WallboardNewsServiceTest extends TestCase
{
    private const NDT_FEED = 'https://nationaaldroneteam.nl/in_het_nieuws/feed/';

    private const NDT_FEED_PAGE_TWO = 'https://nationaaldroneteam.nl/in_het_nieuws/feed/?paged=2';

    private const DRONEWATCH_FEED = 'https://www.dronewatch.nl/feed/';

    private const CUSTOM_FEED = 'https://news.example.org/drone/feed.xml';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 12:00:00', 'Europe/Amsterdam'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_combined_page_returns_only_last_seven_days_when_any_recent_news_exists(): void
    {
        Http::fake([
            self::DRONEWATCH_FEED => Http::response($this->feed([
                $this->feedItem(
                    'Recent dronebericht',
                    'https://www.dronewatch.nl/2026/07/19/recent-dronebericht/',
                    'Sun, 19 Jul 2026 08:00:00 +0000',
                    '<p>Actuele <strong>drone-inhoud</strong>.</p><script>alert("niet tonen")</script> Lees verder https://www.dronewatch.nl/2026/07/19/recent-dronebericht/',
                ),
                $this->feedItem(
                    'Toekomstig bericht',
                    'https://www.dronewatch.nl/2026/07/20/toekomstig-bericht/',
                    'Mon, 20 Jul 2026 08:00:00 +0000',
                    'Dit bericht mag nog niet zichtbaar zijn.',
                ),
                $this->feedItem(
                    'Te oud dronebericht',
                    'https://www.dronewatch.nl/2026/07/01/oud-dronebericht/',
                    'Wed, 01 Jul 2026 08:00:00 +0000',
                    'Oude inhoud.',
                ),
            ]), 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']),
            self::NDT_FEED => Http::response($this->feed([
                $this->feedItem(
                    'NDT-bericht uit maart',
                    'https://nationaaldroneteam.nl/in_het_nieuws/ndt-maart/',
                    'Fri, 27 Mar 2026 13:08:57 +0000',
                ),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
            self::NDT_FEED_PAGE_TWO => Http::response($this->feed([]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $result = $this->service()->pages([$this->page('news', ['ndt', 'dronewatch'], 12)]);
        $news = $result['pages']['news'];

        $this->assertFalse($news['fallback_used']);
        $this->assertSame(7, $news['lookback_days']);
        $this->assertCount(1, $news['items']);
        $this->assertSame('Recent dronebericht', $news['items'][0]['title']);
        $this->assertSame('Dronewatch', $news['items'][0]['source_label']);
        $this->assertSame('Actuele drone-inhoud.', $news['items'][0]['excerpt']);
        $this->assertSame('2026-07-19T10:00:00+02:00', $news['items'][0]['published_at']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/in_het_nieuws/ndt-maart/'));
    }

    public function test_when_no_recent_items_exist_it_falls_back_to_latest_configured_count_with_ndt_content(): void
    {
        $firstUrl = 'https://nationaaldroneteam.nl/in_het_nieuws/eerste-bericht/';
        $secondUrl = 'https://nationaaldroneteam.nl/in_het_nieuws/tweede-bericht/';
        $thirdUrl = 'https://nationaaldroneteam.nl/in_het_nieuws/derde-bericht/';
        Http::fake([
            self::NDT_FEED => Http::response($this->feed([
                $this->feedItem('Eerste bericht', $firstUrl, 'Fri, 27 Mar 2026 13:00:00 +0000'),
                $this->feedItem('Tweede bericht', $secondUrl, 'Sun, 22 Mar 2026 11:00:00 +0000'),
                $this->feedItem('Derde bericht', $thirdUrl, 'Sun, 15 Mar 2026 11:00:00 +0000'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
            self::NDT_FEED_PAGE_TWO => Http::response($this->feed([]), 200, ['Content-Type' => 'application/rss+xml']),
            $firstUrl => Http::response($this->ndtDetail(
                'Na een <strong>intensieve zoekactie</strong> heeft het team de inzet zorgvuldig afgerond. <script>geheime code</script>',
                'https://nationaaldroneteam.nl/uploads/eerste.jpg',
            ), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            $secondUrl => Http::response($this->ndtDetail(
                'Het Nationaal Drone Team trainde communicatie en samenwerking tijdens een realistisch scenario.',
            ), 200, ['Content-Type' => 'text/html']),
        ]);

        $news = $this->service()->pages([$this->page('ndt-news', ['ndt'], 2)])['pages']['ndt-news'];

        $this->assertTrue($news['fallback_used']);
        $this->assertSame(['Eerste bericht', 'Tweede bericht'], array_column($news['items'], 'title'));
        $this->assertSame(
            'Na een intensieve zoekactie heeft het team de inzet zorgvuldig afgerond.',
            $news['items'][0]['excerpt'],
        );
        $this->assertStringNotContainsString('script', implode(' ', array_column($news['items'], 'excerpt')));
        $this->assertStringNotContainsString('geheime code', implode(' ', array_column($news['items'], 'excerpt')));
        $this->assertMatchesRegularExpression(
            '#^/api/wallboard/news-images/[a-f0-9]{64}$#',
            (string) $news['items'][0]['image_url'],
        );
        $this->assertStringNotContainsString('nationaaldroneteam.nl/uploads', json_encode($news, JSON_THROW_ON_ERROR));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === $thirdUrl);
    }

    public function test_sources_are_independently_selectable_and_maximum_applies_to_combined_sorted_items(): void
    {
        $ndtUrl = 'https://nationaaldroneteam.nl/in_het_nieuws/vandaag-ndt/';
        Http::fake([
            self::NDT_FEED => Http::response($this->feed([
                $this->feedItem('NDT vandaag', $ndtUrl, 'Sun, 19 Jul 2026 09:30:00 +0000'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
            self::NDT_FEED_PAGE_TWO => Http::response($this->feed([]), 200, ['Content-Type' => 'application/rss+xml']),
            self::DRONEWATCH_FEED => Http::response($this->feed([
                $this->feedItem('Dronewatch nieuwste', 'https://www.dronewatch.nl/nieuwste/', 'Sun, 19 Jul 2026 10:00:00 +0000', 'Nieuwste inhoud'),
                $this->feedItem('Dronewatch ouder', 'https://www.dronewatch.nl/ouder/', 'Sun, 19 Jul 2026 09:00:00 +0000', 'Oudere inhoud'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
            $ndtUrl => Http::response($this->ndtDetail(
                'Vandaag publiceerde het Nationaal Drone Team een inhoudelijke operationele update voor de achterban.',
            ), 200, ['Content-Type' => 'text/html']),
        ]);

        $pages = [
            $this->page('only-ndt', ['ndt'], 12),
            $this->page('only-dronewatch', ['dronewatch'], 12),
            $this->page('combined', ['ndt', 'dronewatch'], 2),
        ];
        $results = $this->service()->pages($pages)['pages'];

        $this->assertSame(['ndt'], array_values(array_unique(array_column($results['only-ndt']['items'], 'source'))));
        $this->assertSame(['dronewatch'], array_values(array_unique(array_column($results['only-dronewatch']['items'], 'source'))));
        $this->assertSame(
            ['Dronewatch nieuwste', 'NDT vandaag'],
            array_column($results['combined']['items'], 'title'),
        );
    }

    public function test_no_news_page_performs_no_network_work(): void
    {
        Http::fake();

        $result = $this->service()->pages([[
            'id' => 'map',
            'type' => 'map',
            'options' => [],
        ]]);

        $this->assertSame([], $result['pages']);
        Http::assertNothingSent();
    }

    public function test_only_dronewatch_page_never_fetches_ndt(): void
    {
        Http::fake([
            self::DRONEWATCH_FEED => Http::response($this->feed([
                $this->feedItem('Alleen Dronewatch', 'https://www.dronewatch.nl/alleen/', 'Sun, 19 Jul 2026 08:00:00 +0000', 'Inhoud'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $items = $this->service()->pages([$this->page('news', ['dronewatch'], 5)])['pages']['news']['items'];

        $this->assertCount(1, $items);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'nationaaldroneteam.nl'));
    }

    public function test_stale_source_data_survives_a_later_upstream_failure(): void
    {
        Http::fakeSequence(self::DRONEWATCH_FEED)
            ->push($this->feed([
                $this->feedItem('Gecachet bericht', 'https://www.dronewatch.nl/gecachet/', 'Sun, 19 Jul 2026 08:00:00 +0000', 'Gecachte inhoud'),
            ]), 200, ['Content-Type' => 'application/rss+xml'])
            ->push('tijdelijk onbeschikbaar', 503, ['Content-Type' => 'text/plain']);
        $service = $this->service();

        $first = $service->pages([$this->page('news', ['dronewatch'], 5)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 12:16:00', 'Europe/Amsterdam'));
        $stale = $service->pages([$this->page('news', ['dronewatch'], 5)]);

        $this->assertSame('Gecachet bericht', $first['pages']['news']['items'][0]['title']);
        $this->assertSame('Gecachet bericht', $stale['pages']['news']['items'][0]['title']);
        Http::assertSentCount(2);
    }

    public function test_malformed_oversized_or_wrong_content_type_sources_fail_closed(): void
    {
        Http::fake([
            self::DRONEWATCH_FEED => Http::response('<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss/>', 200, ['Content-Type' => 'application/rss+xml']),
            self::NDT_FEED => Http::response(str_repeat('x', WallboardNewsService::MAX_RESPONSE_BYTES + 1), 200, ['Content-Type' => 'application/rss+xml']),
            self::NDT_FEED_PAGE_TWO => Http::response('{}', 200, ['Content-Type' => 'application/json']),
        ]);

        $results = $this->service()->pages([
            $this->page('dronewatch', ['dronewatch'], 5),
            $this->page('ndt', ['ndt'], 5),
        ])['pages'];

        $this->assertSame([], $results['dronewatch']['items']);
        $this->assertSame([], $results['ndt']['items']);
    }

    public function test_feed_links_outside_the_fixed_official_hosts_are_ignored(): void
    {
        Http::fake([
            self::DRONEWATCH_FEED => Http::response($this->feed([
                $this->feedItem('Kwaadaardige omleiding', 'https://127.0.0.1/internal', 'Sun, 19 Jul 2026 08:00:00 +0000', 'Niet gebruiken'),
                $this->feedItem('Veilig', 'https://www.dronewatch.nl/veilig/', 'Sun, 19 Jul 2026 07:00:00 +0000', 'Wel gebruiken'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $items = $this->service()->pages([$this->page('news', ['dronewatch'], 5)])['pages']['news']['items'];

        $this->assertSame(['Veilig'], array_column($items, 'title'));
        $this->assertStringNotContainsString('127.0.0.1', json_encode($items, JSON_THROW_ON_ERROR));
    }

    public function test_future_items_are_excluded_from_recent_and_fallback_results(): void
    {
        Http::fake([
            self::DRONEWATCH_FEED => Http::response($this->feed([
                $this->feedItem('Toekomst', 'https://www.dronewatch.nl/toekomst/', 'Mon, 20 Jul 2026 08:00:00 +0000', 'Nog niet publiceren'),
                $this->feedItem('Historisch', 'https://www.dronewatch.nl/historisch/', 'Wed, 01 Jul 2026 08:00:00 +0000', 'Wel als fallback tonen'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $news = $this->service()->pages([$this->page('news', ['dronewatch'], 5)])['pages']['news'];

        $this->assertTrue($news['fallback_used']);
        $this->assertSame(['Historisch'], array_column($news['items'], 'title'));
    }

    public function test_custom_rss_is_combined_and_uses_sanitized_namespaced_content(): void
    {
        Http::fake([
            self::CUSTOM_FEED => Http::response(
                '<?xml version="1.0"?><rss xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><item>'
                .'<title>Eigen dronebericht</title><link>https://news.example.org/drone/eigen-bericht</link>'
                .'<pubDate>Sun, 19 Jul 2026 09:45:00 +0000</pubDate>'
                .'<description><![CDATA[<img src="https://news.example.org/beeld.jpg">]]></description>'
                .'<content:encoded><![CDATA[<p>Inhoud van de <strong>eigen RSS-bron</strong>.</p><script>niet tonen</script>]]></content:encoded>'
                .'</item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml; charset=UTF-8'],
            ),
        ]);

        $custom = [['id' => 'eigen_nieuws', 'label' => 'Eigen nieuws', 'url' => self::CUSTOM_FEED]];
        $news = $this->service()->pages([$this->page('custom', [], 4, $custom)])['pages']['custom'];

        $this->assertFalse($news['fallback_used']);
        $this->assertCount(1, $news['items']);
        $this->assertSame('custom', $news['items'][0]['source']);
        $this->assertSame('eigen_nieuws', $news['items'][0]['source_id']);
        $this->assertSame('Eigen nieuws', $news['items'][0]['source_label']);
        $this->assertSame('Inhoud van de eigen RSS-bron.', $news['items'][0]['excerpt']);
        $this->assertMatchesRegularExpression('#^/api/wallboard/news-images/[a-f0-9]{64}$#', (string) $news['items'][0]['image_url']);
        $this->assertStringNotContainsString('news.example.org/beeld.jpg', json_encode($news, JSON_THROW_ON_ERROR));
        Http::assertSentCount(1);
    }

    public function test_image_proxy_fetches_only_registered_same_origin_raster_images(): void
    {
        $imageUrl = 'https://news.example.org/images/article.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        Http::fake([
            self::CUSTOM_FEED => Http::response(
                '<?xml version="1.0"?><rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
                .'<title>Nieuws met beeld</title><link>https://news.example.org/drone/met-beeld</link>'
                .'<pubDate>Sun, 19 Jul 2026 09:45:00 +0000</pubDate>'
                .'<media:content url="'.$imageUrl.'" type="image/png" />'
                .'</item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
            $imageUrl => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $service = $this->service();
        $custom = [['id' => 'eigen', 'label' => 'Eigen', 'url' => self::CUSTOM_FEED]];

        $item = $service->pages([$this->page('custom', [], 4, $custom)])['pages']['custom']['items'][0];
        $this->assertMatchesRegularExpression('#^/api/wallboard/news-images/([a-f0-9]{64})$#', (string) $item['image_url']);
        preg_match('#([a-f0-9]{64})$#', (string) $item['image_url'], $matches);
        $image = $service->image($matches[1]);

        $this->assertSame('image/png', $image['content_type'] ?? null);
        $this->assertSame($png, $image['body'] ?? null);
        $this->assertNull($service->image(str_repeat('0', 64)));
        Http::assertSent(fn (Request $request): bool => $request->url() === $imageUrl);
    }

    public function test_cross_origin_or_non_raster_images_are_never_exposed(): void
    {
        Http::fake([
            self::CUSTOM_FEED => Http::response(
                '<?xml version="1.0"?><rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
                .'<title>Nieuws zonder veilig beeld</title><link>https://news.example.org/drone/zonder-beeld</link>'
                .'<pubDate>Sun, 19 Jul 2026 09:45:00 +0000</pubDate>'
                .'<media:content url="https://attacker.example/internal.svg" type="image/svg+xml" />'
                .'</item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ]);
        $custom = [['id' => 'eigen', 'label' => 'Eigen', 'url' => self::CUSTOM_FEED]];

        $item = $this->service()->pages([$this->page('custom', [], 4, $custom)])['pages']['custom']['items'][0];

        $this->assertNull($item['image_url']);
        $this->assertStringNotContainsString('attacker.example', json_encode($item, JSON_THROW_ON_ERROR));
    }

    public function test_custom_feed_rejects_private_dns_and_never_sends_the_request(): void
    {
        Http::fake();
        $service = new WallboardNewsService(static fn (string $host): array => ['93.184.216.34', '127.0.0.1']);
        $custom = [['id' => 'eigen', 'label' => 'Eigen', 'url' => self::CUSTOM_FEED]];

        $news = $service->pages([$this->page('custom', [], 4, $custom)])['pages']['custom'];

        $this->assertSame([], $news['items']);
        Http::assertNothingSent();
    }

    public function test_custom_feed_uses_safe_feed_url_when_item_link_is_off_origin(): void
    {
        Http::fake([
            self::CUSTOM_FEED => Http::response($this->feed([
                $this->feedItem('Veilig weergegeven', 'https://attacker.example/article', 'Sun, 19 Jul 2026 08:00:00 +0000', 'Inhoud uit de feed.'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);
        $custom = [['id' => 'eigen', 'label' => 'Eigen', 'url' => self::CUSTOM_FEED]];

        $items = $this->service()->pages([$this->page('custom', [], 4, $custom)])['pages']['custom']['items'];

        $this->assertCount(1, $items);
        $this->assertSame(self::CUSTOM_FEED, $items[0]['url']);
        Http::assertSentCount(1);
    }

    public function test_only_one_uncached_custom_feed_is_fetched_per_state_request(): void
    {
        $secondFeed = 'https://second.example.org/feed.xml';
        Http::fake([
            self::CUSTOM_FEED => Http::response($this->feed([
                $this->feedItem('Eerste feed', 'https://news.example.org/drone/eerste', 'Sun, 19 Jul 2026 08:00:00 +0000', 'Eerste inhoud'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
            $secondFeed => Http::response($this->feed([
                $this->feedItem('Tweede feed', 'https://second.example.org/tweede', 'Sun, 19 Jul 2026 09:00:00 +0000', 'Tweede inhoud'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);
        $custom = [
            ['id' => 'eerste', 'label' => 'Eerste', 'url' => self::CUSTOM_FEED],
            ['id' => 'tweede', 'label' => 'Tweede', 'url' => $secondFeed],
        ];

        $first = $this->service()->pages([$this->page('custom', [], 8, $custom)]);

        $this->assertSame(['Eerste feed'], array_column($first['pages']['custom']['items'], 'title'));
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === $secondFeed);
    }

    public function test_same_custom_feed_is_fetched_once_and_relabelled_per_page(): void
    {
        Http::fake([
            self::CUSTOM_FEED => Http::response($this->feed([
                $this->feedItem('Gedeeld nieuws', 'https://news.example.org/drone/gedeeld', 'Sun, 19 Jul 2026 08:00:00 +0000', 'Gedeelde inhoud'),
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);
        $pages = [
            $this->page('one', [], 4, [['id' => 'bron_een', 'label' => 'Bron een', 'url' => self::CUSTOM_FEED]]),
            $this->page('two', [], 4, [['id' => 'bron_twee', 'label' => 'Bron twee', 'url' => self::CUSTOM_FEED]]),
        ];

        $results = $this->service()->pages($pages)['pages'];

        $this->assertSame('bron_een', $results['one']['items'][0]['source_id']);
        $this->assertSame('Bron een', $results['one']['items'][0]['source_label']);
        $this->assertSame('bron_twee', $results['two']['items'][0]['source_id']);
        $this->assertSame('Bron twee', $results['two']['items'][0]['source_label']);
        Http::assertSentCount(1);
    }

    public function test_custom_feed_uses_stale_cache_after_upstream_failure(): void
    {
        Http::fakeSequence(self::CUSTOM_FEED)
            ->push($this->feed([
                $this->feedItem('Gecachet custom nieuws', 'https://news.example.org/drone/cache', 'Sun, 19 Jul 2026 08:00:00 +0000', 'Gecachte inhoud'),
            ]), 200, ['Content-Type' => 'application/rss+xml'])
            ->push('tijdelijk onbeschikbaar', 503, ['Content-Type' => 'text/plain']);
        $service = $this->service();
        $custom = [['id' => 'eigen', 'label' => 'Eigen', 'url' => self::CUSTOM_FEED]];

        $service->pages([$this->page('custom', [], 4, $custom)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 12:16:00', 'Europe/Amsterdam'));
        $stale = $service->pages([$this->page('custom', [], 4, $custom)]);

        $this->assertSame('Gecachet custom nieuws', $stale['pages']['custom']['items'][0]['title']);
        Http::assertSentCount(2);
    }

    public function test_ndt_page_two_cannot_replace_stale_cache_when_primary_feed_fails(): void
    {
        $oldUrl = 'https://nationaaldroneteam.nl/in_het_nieuws/gecacheerd/';
        $partialUrl = 'https://nationaaldroneteam.nl/in_het_nieuws/alleen-pagina-twee/';
        Http::fakeSequence(self::NDT_FEED)
            ->push($this->feed([$this->feedItem('Gecacheerd NDT', $oldUrl, 'Sun, 19 Jul 2026 08:00:00 +0000')]), 200, ['Content-Type' => 'application/rss+xml'])
            ->push('storing', 503, ['Content-Type' => 'text/plain']);
        Http::fakeSequence(self::NDT_FEED_PAGE_TWO)
            ->push($this->feed([]), 200, ['Content-Type' => 'application/rss+xml'])
            ->push($this->feed([$this->feedItem('Partieel', $partialUrl, 'Sun, 19 Jul 2026 09:00:00 +0000')]), 200, ['Content-Type' => 'application/rss+xml']);
        $service = $this->service();

        $service->pages([$this->page('ndt', ['ndt'], 4)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 12:16:00', 'Europe/Amsterdam'));
        $stale = $service->pages([$this->page('ndt', ['ndt'], 4)]);

        $this->assertSame(['Gecacheerd NDT'], array_column($stale['pages']['ndt']['items'], 'title'));
        $this->assertNotContains('Partieel', array_column($stale['pages']['ndt']['items'], 'title'));
    }

    private function service(): WallboardNewsService
    {
        return new WallboardNewsService(static fn (string $host): array => ['93.184.216.34']);
    }

    /** @return array<string, mixed> */
    private function page(string $id, array $sources, int $maximumItems, array $customSources = []): array
    {
        return [
            'id' => $id,
            'name' => 'Nieuws',
            'type' => 'news',
            'duration_seconds' => 30,
            'options' => [
                'sources' => $sources,
                'custom_sources' => $customSources,
                'max_items' => $maximumItems,
            ],
        ];
    }

    /** @param list<string> $items */
    private function feed(array $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
            .implode('', $items)
            .'</channel></rss>';
    }

    private function feedItem(string $title, string $url, string $publishedAt, string $description = ''): string
    {
        return '<item><title>'.htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</title>'
            .'<link>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</link>'
            .'<pubDate>'.htmlspecialchars($publishedAt, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</pubDate>'
            .'<description><![CDATA['.$description.']]></description></item>';
    }

    private function ndtDetail(string $paragraph, ?string $imageUrl = null): string
    {
        $head = $imageUrl === null
            ? ''
            : '<head><meta property="og:image" content="'.htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8').'"></head>';

        return '<!doctype html><html>'.$head.'<body><main><p class="dmach-acf-value">'.$paragraph.'</p>'
            .'<p class="dmach-acf-value">Tweede alinea die niet volledig op het wallboard hoeft te worden gereproduceerd.</p>'
            .'</main></body></html>';
    }
}
