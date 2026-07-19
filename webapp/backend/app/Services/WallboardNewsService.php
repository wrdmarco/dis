<?php

namespace App\Services;

use App\Support\ApiDateTime;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WallboardNewsService
{
    public const LOOKBACK_DAYS = 7;

    public const MAX_EXCERPT_LENGTH = 700;

    public const MAX_RESPONSE_BYTES = 524_288;

    private const MAX_SOURCE_ITEMS = 50;

    private const MAX_COLD_DETAIL_FETCHES = WallboardConfiguration::MAX_NEWS_MAX_ITEMS;

    private const CACHE_VERSION = 2;

    private const SOURCE_CACHE_SECONDS = 900;

    private const SOURCE_STALE_CACHE_SECONDS = 86_400;

    private const SOURCE_FAILURE_CACHE_SECONDS = 60;

    private const DETAIL_CACHE_SECONDS = 86_400;

    private const DETAIL_STALE_CACHE_SECONDS = 604_800;

    private const DETAIL_FAILURE_CACHE_SECONDS = 300;

    private const DRONEWATCH_FEED_URL = 'https://www.dronewatch.nl/feed/';

    private const NDT_FEED_URL = 'https://nationaaldroneteam.nl/in_het_nieuws/feed/';

    private const NDT_FEED_PAGE_TWO_URL = 'https://nationaaldroneteam.nl/in_het_nieuws/feed/?paged=2';

    /** @var array<string, string> */
    private const SOURCE_LABELS = [
        'ndt' => 'Nationaal Drone Team',
        'dronewatch' => 'Dronewatch',
    ];

    /** @var list<string> */
    private const XML_CONTENT_TYPES = [
        'application/atom+xml',
        'application/rdf+xml',
        'application/rss+xml',
        'application/xml',
        'text/xml',
    ];

    /** @var list<string> */
    private const DENIED_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '100::/64',
        '2001::/32',
        '2001:db8::/32',
        '2001:10::/28',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /** @var Closure(string): list<string> */
    private readonly Closure $dnsResolver;

    /**
     * @param  (Closure(string): list<string>)|null  $dnsResolver
     */
    public function __construct(?Closure $dnsResolver = null)
    {
        $this->dnsResolver = $dnsResolver ?? Closure::fromCallable([$this, 'resolveDns']);
    }

    /**
     * Resolve all configured news pages. Built-in URLs stay server-owned; custom
     * RSS URLs pass strict transport and DNS validation before every cold fetch.
     * NDT detail pages are fetched concurrently, at most the global item cap.
     *
     * @param  array<int, mixed>  $pages
     * @return array{pages: array<string, array{items: list<array{id: string, source: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>, fallback_used: bool, lookback_days: int}>, generated_at: string}
     */
    public function pages(array $pages): array
    {
        $newsPages = collect($pages)
            ->filter(fn (mixed $page): bool => is_array($page) && ($page['type'] ?? null) === 'news')
            ->values();

        if ($newsPages->isEmpty()) {
            return ['pages' => [], 'generated_at' => ApiDateTime::now()];
        }

        $requestedSources = $newsPages
            ->flatMap(fn (array $page): array => (array) (($page['options'] ?? [])['sources'] ?? []))
            ->filter(fn (mixed $source): bool => is_string($source)
                && in_array($source, WallboardConfiguration::NEWS_SOURCES, true))
            ->unique()
            ->values()
            ->all();
        $requestedCustomSources = $newsPages
            ->flatMap(fn (array $page): array => (array) (($page['options'] ?? [])['custom_sources'] ?? []))
            ->map(fn (mixed $source): ?array => $this->validCustomSource($source))
            ->filter()
            ->unique(fn (array $source): string => $this->customSourceKey($source))
            ->values()
            ->all();
        $sourceItems = $this->sourceItems($requestedSources);
        $customSourceItems = $this->customSourceItems($requestedCustomSources);

        $selectedByPage = [];
        foreach ($newsPages as $page) {
            $pageId = (string) ($page['id'] ?? '');
            $options = (array) ($page['options'] ?? []);
            $sources = array_values((array) ($options['sources'] ?? []));
            $customSources = array_values((array) ($options['custom_sources'] ?? []));
            $maximumItems = (int) ($options['max_items'] ?? WallboardConfiguration::DEFAULT_NEWS_MAX_ITEMS);
            $combined = collect($sources)
                ->flatMap(fn (mixed $source): array => is_string($source) ? ($sourceItems[$source] ?? []) : [])
                ->concat(collect($customSources)->flatMap(function (mixed $source) use ($customSourceItems): array {
                    $validSource = $this->validCustomSource($source);

                    return $validSource === null
                        ? []
                        : $this->relabelCustomItems(
                            $customSourceItems[$this->customSourceKey($validSource)] ?? [],
                            $validSource,
                        );
                }))
                ->unique('id')
                ->values()
                ->all();
            $selectedByPage[$pageId] = $this->selectItems($combined, $maximumItems);
        }

        $ndtUrls = collect($selectedByPage)
            ->flatMap(fn (array $result): array => $result['items'])
            ->filter(fn (array $item): bool => $item['source'] === 'ndt')
            ->pluck('url')
            ->unique()
            ->values()
            ->all();
        $ndtExcerpts = $this->ndtExcerpts($ndtUrls);

        foreach ($selectedByPage as &$result) {
            foreach ($result['items'] as &$item) {
                if ($item['source'] === 'ndt' && isset($ndtExcerpts[$item['url']])) {
                    $item['excerpt'] = $ndtExcerpts[$item['url']];
                }
            }
            unset($item);
        }
        unset($result);

        return [
            'pages' => $selectedByPage,
            'generated_at' => ApiDateTime::now(),
        ];
    }

    /**
     * @param  list<string>  $sources
     * @return array<string, list<array{id: string, source: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>>
     */
    private function sourceItems(array $sources): array
    {
        $results = [];
        $sourcesToFetch = [];
        $documentsToFetch = [];

        foreach ($sources as $source) {
            if (! isset(self::SOURCE_LABELS[$source])) {
                continue;
            }

            $cached = Cache::get($this->sourceFreshKey($source));
            $cachedItems = $this->cachedItems($source, $cached);
            if ($cachedItems !== null) {
                $results[$source] = $cachedItems;

                continue;
            }

            if (Cache::has($this->sourceFailureKey($source))) {
                $results[$source] = $this->cachedItems(
                    $source,
                    Cache::get($this->sourceStaleKey($source)),
                ) ?? [];

                continue;
            }

            $sourcesToFetch[] = $source;
            if ($source === 'ndt') {
                $documentsToFetch['ndt:1'] = [
                    'url' => self::NDT_FEED_URL,
                    'content_types' => self::XML_CONTENT_TYPES,
                ];
                $documentsToFetch['ndt:2'] = [
                    'url' => self::NDT_FEED_PAGE_TWO_URL,
                    'content_types' => self::XML_CONTENT_TYPES,
                ];
            } else {
                $documentsToFetch['dronewatch'] = [
                    'url' => self::DRONEWATCH_FEED_URL,
                    'content_types' => self::XML_CONTENT_TYPES,
                ];
            }
        }

        $documents = $this->fetchDocuments($documentsToFetch);
        foreach ($sourcesToFetch as $source) {
            $items = match ($source) {
                'ndt' => $this->parseNdtFeeds([
                    $documents['ndt:1'] ?? null,
                    $documents['ndt:2'] ?? null,
                ]),
                'dronewatch' => $this->parseDronewatchFeed($documents['dronewatch'] ?? null),
                default => null,
            };

            if ($items !== null && $items !== []) {
                $payload = ['version' => self::CACHE_VERSION, 'items' => $items];
                Cache::put($this->sourceFreshKey($source), $payload, now()->addSeconds(self::SOURCE_CACHE_SECONDS));
                Cache::put($this->sourceStaleKey($source), $payload, now()->addSeconds(self::SOURCE_STALE_CACHE_SECONDS));
                Cache::forget($this->sourceFailureKey($source));
                $results[$source] = $items;

                continue;
            }

            Cache::put($this->sourceFailureKey($source), true, now()->addSeconds(self::SOURCE_FAILURE_CACHE_SECONDS));
            $results[$source] = $this->cachedItems(
                $source,
                Cache::get($this->sourceStaleKey($source)),
            ) ?? [];
        }

        return $results;
    }

    /**
     * @param  list<array{id: string, label: string, url: string}>  $sources
     * @return array<string, list<array{id: string, source: string, source_id: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>>
     */
    private function customSourceItems(array $sources): array
    {
        $results = [];
        $toFetch = [];
        $locks = [];
        $coldFetchAvailable = true;

        foreach ($sources as $source) {
            $key = $this->customSourceKey($source);
            $cacheIdentity = 'custom-'.$key;
            $cachedItems = $this->cachedCustomItems($source, Cache::get($this->sourceFreshKey($cacheIdentity)));
            if ($cachedItems !== null) {
                $results[$key] = $cachedItems;

                continue;
            }

            if (Cache::has($this->sourceFailureKey($cacheIdentity)) || ! $coldFetchAvailable) {
                $results[$key] = $this->cachedCustomItems(
                    $source,
                    Cache::get($this->sourceStaleKey($cacheIdentity)),
                ) ?? [];

                continue;
            }

            $lock = Cache::lock('wallboard:news:custom-fetch:'.$key, 10);
            if (! $lock->get()) {
                $results[$key] = $this->cachedCustomItems(
                    $source,
                    Cache::get($this->sourceStaleKey($cacheIdentity)),
                ) ?? [];

                continue;
            }

            $coldFetchAvailable = false;
            $locks[$key] = $lock;
            $toFetch[$key] = [
                'url' => $source['url'],
                'content_types' => self::XML_CONTENT_TYPES,
                'official' => false,
                'source' => $source,
                'cache_identity' => $cacheIdentity,
            ];
        }

        try {
            $documents = $this->fetchDocuments($toFetch);
            foreach ($toFetch as $key => $request) {
                $source = $request['source'];
                $cacheIdentity = $request['cache_identity'];
                $items = $this->parseCustomFeed($documents[$key] ?? null, $source);
                if ($items !== null && $items !== []) {
                    $payload = ['version' => self::CACHE_VERSION, 'items' => $items];
                    Cache::put($this->sourceFreshKey($cacheIdentity), $payload, now()->addSeconds(self::SOURCE_CACHE_SECONDS));
                    Cache::put($this->sourceStaleKey($cacheIdentity), $payload, now()->addSeconds(self::SOURCE_STALE_CACHE_SECONDS));
                    Cache::forget($this->sourceFailureKey($cacheIdentity));
                    $results[$key] = $items;

                    continue;
                }

                Cache::put($this->sourceFailureKey($cacheIdentity), true, now()->addSeconds(self::SOURCE_FAILURE_CACHE_SECONDS));
                $results[$key] = $this->cachedCustomItems(
                    $source,
                    Cache::get($this->sourceStaleKey($cacheIdentity)),
                ) ?? [];
            }
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        return $results;
    }

    /**
     * @param  list<array{id: string, source: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>  $items
     * @return array{items: list<array{id: string, source: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>, fallback_used: bool, lookback_days: int}
     */
    private function selectItems(array $items, int $maximumItems): array
    {
        $maximumItems = min(
            WallboardConfiguration::MAX_NEWS_MAX_ITEMS,
            max(WallboardConfiguration::MIN_NEWS_MAX_ITEMS, $maximumItems),
        );
        $now = CarbonImmutable::instance(now());
        $cutoff = $now->subDays(self::LOOKBACK_DAYS);
        $sorted = collect($items)
            ->filter(function (mixed $item) use ($now): bool {
                if (! is_array($item)) {
                    return false;
                }
                $publishedAt = $this->itemDate($item);

                return $publishedAt !== null && $publishedAt->lessThanOrEqualTo($now);
            })
            ->sortByDesc(fn (array $item): int => $this->itemDate($item)?->getTimestamp() ?? 0)
            ->values();
        $recent = $sorted
            ->filter(function (array $item) use ($cutoff): bool {
                $publishedAt = $this->itemDate($item);

                return $publishedAt !== null
                    && $publishedAt->greaterThanOrEqualTo($cutoff);
            })
            ->values();
        $fallbackUsed = $recent->isEmpty() && $sorted->isNotEmpty();

        return [
            'items' => ($fallbackUsed ? $sorted : $recent)->take($maximumItems)->all(),
            'fallback_used' => $fallbackUsed,
            'lookback_days' => self::LOOKBACK_DAYS,
        ];
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, string>
     */
    private function ndtExcerpts(array $urls): array
    {
        $results = [];
        $toFetch = [];

        foreach ($urls as $url) {
            if (! $this->isOfficialArticleUrl('ndt', $url)) {
                continue;
            }

            $cached = Cache::get($this->detailFreshKey($url));
            if (is_string($cached) && ($excerpt = $this->plainText($cached, self::MAX_EXCERPT_LENGTH)) !== '') {
                $results[$url] = $excerpt;

                continue;
            }

            if (Cache::has($this->detailFailureKey($url))) {
                $stale = Cache::get($this->detailStaleKey($url));
                if (is_string($stale) && ($excerpt = $this->plainText($stale, self::MAX_EXCERPT_LENGTH)) !== '') {
                    $results[$url] = $excerpt;
                }

                continue;
            }

            if (count($toFetch) < self::MAX_COLD_DETAIL_FETCHES) {
                $toFetch[hash('sha256', $url)] = [
                    'url' => $url,
                    'content_types' => ['text/html'],
                ];
            }
        }

        $documents = $this->fetchDocuments($toFetch);
        foreach ($toFetch as $key => $request) {
            $url = $request['url'];
            $excerpt = $this->parseNdtDetail($documents[$key] ?? null);
            if ($excerpt !== null && $excerpt !== '') {
                Cache::put($this->detailFreshKey($url), $excerpt, now()->addSeconds(self::DETAIL_CACHE_SECONDS));
                Cache::put($this->detailStaleKey($url), $excerpt, now()->addSeconds(self::DETAIL_STALE_CACHE_SECONDS));
                Cache::forget($this->detailFailureKey($url));
                $results[$url] = $excerpt;

                continue;
            }

            Cache::put($this->detailFailureKey($url), true, now()->addSeconds(self::DETAIL_FAILURE_CACHE_SECONDS));
            $stale = Cache::get($this->detailStaleKey($url));
            if (is_string($stale) && ($stale = $this->plainText($stale, self::MAX_EXCERPT_LENGTH)) !== '') {
                $results[$url] = $stale;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, array{url: string, content_types: list<string>, official?: bool}>  $requests
     * @return array<string, string|null>
     */
    private function fetchDocuments(array $requests): array
    {
        if ($requests === [] || ! defined('CURLOPT_RESOLVE') || ! defined('CURLOPT_PROXY')) {
            return [];
        }

        $validated = [];
        foreach ($requests as $key => $request) {
            $target = $this->validatedTarget($request['url'], ($request['official'] ?? true) === true);
            if ($target !== null) {
                $validated[$key] = [...$request, 'target' => $target];
            }
        }
        if ($validated === []) {
            return [];
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($validated): void {
                foreach ($validated as $key => $request) {
                    $target = $request['target'];
                    $curlOptions = [
                        CURLOPT_RESOLVE => [$this->curlResolveEntry($target['host'], $target['ip'])],
                        CURLOPT_PROXY => '',
                    ];
                    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                        $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                    }

                    $pool->as((string) $key)
                        ->accept(implode(', ', $request['content_types']))
                        ->withHeaders([
                            'Accept-Encoding' => 'identity',
                            'User-Agent' => 'DIS-Wallboard-News/1.0',
                        ])
                        ->connectTimeout(2)
                        ->timeout(4)
                        ->withoutRedirecting()
                        ->withOptions([
                            'allow_redirects' => false,
                            'decode_content' => false,
                            'http_errors' => false,
                            'proxy' => null,
                            'verify' => true,
                            'curl' => $curlOptions,
                            'on_headers' => static function ($response): void {
                                $length = trim((string) $response->getHeaderLine('Content-Length'));
                                if ($length !== '' && ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                                    throw new \RuntimeException('News response is too large.');
                                }
                            },
                            'progress' => static function (
                                int|float $downloadTotal,
                                int|float $downloadedBytes,
                                int|float $uploadTotal,
                                int|float $uploadedBytes,
                            ): void {
                                unset($downloadTotal, $uploadTotal, $uploadedBytes);
                                if ($downloadedBytes > self::MAX_RESPONSE_BYTES) {
                                    throw new \RuntimeException('News response is too large.');
                                }
                            },
                        ])
                        ->get($request['url']);
                }
            }, self::MAX_COLD_DETAIL_FETCHES);
        } catch (Throwable) {
            return [];
        }

        $documents = [];
        foreach ($validated as $key => $request) {
            $response = $responses[$key] ?? null;
            if (! $response instanceof Response
                || $response->status() !== 200
                || ! $this->hasContentType($response->header('Content-Type'), $request['content_types'])) {
                $documents[$key] = null;

                continue;
            }

            $body = $response->body();
            $documents[$key] = $body !== '' && strlen($body) <= self::MAX_RESPONSE_BYTES
                ? $body
                : null;
        }

        return $documents;
    }

    /**
     * @return list<array{id: string, source: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>|null
     */
    private function parseDronewatchFeed(?string $xml): ?array
    {
        $document = $this->document($xml, true);
        if (! $document instanceof DOMDocument) {
            return null;
        }

        $nodes = (new DOMXPath($document))->query('//*[local-name()="item"]');
        if ($nodes === false) {
            return null;
        }

        $items = [];
        $visitedNodes = 0;
        foreach ($nodes as $node) {
            if ($visitedNodes++ >= self::MAX_SOURCE_ITEMS || count($items) >= self::MAX_SOURCE_ITEMS) {
                break;
            }
            if (! $node instanceof DOMElement) {
                continue;
            }

            $title = $this->plainText($this->firstChildText($node, ['title']), 240);
            $excerpt = $this->newsExcerpt($this->firstChildText($node, ['description', 'summary']));
            $url = trim($this->firstChildText($node, ['link']));
            $publishedAt = $this->externalDate($this->firstChildText($node, ['pubdate', 'published', 'updated']));
            if ($title === '' || ! $publishedAt instanceof CarbonImmutable || ! $this->isOfficialArticleUrl('dronewatch', $url)) {
                continue;
            }

            $items[] = $this->item('dronewatch', 'dronewatch', self::SOURCE_LABELS['dronewatch'], $title, $excerpt, $url, $publishedAt);
        }

        return $items;
    }

    /**
     * @return list<array{id: string, source: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>|null
     */
    private function parseNdtFeeds(array $feeds): ?array
    {
        $primaryDocument = $this->document(is_string($feeds[0] ?? null) ? $feeds[0] : null, true);
        if (! $primaryDocument instanceof DOMDocument) {
            return null;
        }

        $items = [];
        foreach ($feeds as $feedIndex => $xml) {
            $document = $feedIndex === 0
                ? $primaryDocument
                : $this->document(is_string($xml) ? $xml : null, true);
            if (! $document instanceof DOMDocument) {
                continue;
            }
            $nodes = (new DOMXPath($document))->query('//*[local-name()="item"]');
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement || count($items) >= self::MAX_SOURCE_ITEMS) {
                    break 2;
                }

                $title = $this->plainText($this->firstChildText($node, ['title']), 240);
                $url = trim($this->firstChildText($node, ['link']));
                $publishedAt = $this->externalDate($this->firstChildText($node, ['pubdate', 'published', 'updated']));
                if ($title === '' || ! $publishedAt instanceof CarbonImmutable || ! $this->isOfficialArticleUrl('ndt', $url)) {
                    continue;
                }

                $items[] = $this->item('ndt', 'ndt', self::SOURCE_LABELS['ndt'], $title, '', $url, $publishedAt);
            }
        }

        return $items;
    }

    /**
     * @param  array{id: string, label: string, url: string}  $source
     * @return list<array{id: string, source: string, source_id: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>|null
     */
    private function parseCustomFeed(?string $xml, array $source): ?array
    {
        $document = $this->document($xml, true);
        if (! $document instanceof DOMDocument) {
            return null;
        }

        $nodes = (new DOMXPath($document))->query('//*[local-name()="item" or local-name()="entry"]');
        if ($nodes === false) {
            return null;
        }

        $items = [];
        $visitedNodes = 0;
        foreach ($nodes as $node) {
            if ($visitedNodes++ >= self::MAX_SOURCE_ITEMS || count($items) >= self::MAX_SOURCE_ITEMS) {
                break;
            }
            if (! $node instanceof DOMElement) {
                continue;
            }

            $title = $this->plainText($this->firstChildText($node, ['title']), 240);
            $excerpt = $this->firstMeaningfulExcerpt($node, ['description', 'summary', 'content', 'encoded']);
            $candidateUrl = $this->feedItemUrl($node);
            $url = $this->isSameOriginArticleUrl($candidateUrl, $source['url'])
                ? $candidateUrl
                : $source['url'];
            $publishedAt = $this->externalDate($this->firstChildText($node, ['pubdate', 'published', 'updated', 'date']));
            if ($title === ''
                || ! $publishedAt instanceof CarbonImmutable) {
                continue;
            }

            $items[] = $this->item(
                'custom',
                $source['id'],
                $source['label'],
                $title,
                $excerpt,
                $url,
                $publishedAt,
            );
        }

        return $items;
    }

    private function parseNdtDetail(?string $html): ?string
    {
        $document = $this->document($html, false);
        if (! $document instanceof DOMDocument) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $preferred = $xpath->query('//p[contains(concat(" ", normalize-space(@class), " "), " dmach-acf-value ")]');
        $excerpt = $this->firstSubstantialParagraph($preferred);
        if ($excerpt !== '') {
            return $excerpt;
        }

        return ($excerpt = $this->firstSubstantialParagraph($xpath->query('//main//p | //article//p'))) !== ''
            ? $excerpt
            : null;
    }

    private function firstSubstantialParagraph(mixed $nodes): string
    {
        if (! $nodes instanceof \DOMNodeList) {
            return '';
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMNode) {
                continue;
            }
            $text = $this->plainText($node->textContent, self::MAX_EXCERPT_LENGTH);
            if (mb_strlen($text) >= 60 && ! str_contains(mb_strtolower($text), 'algemeen nut beogende instelling')) {
                return $text;
            }
        }

        return '';
    }

    private function document(?string $contents, bool $xml): ?DOMDocument
    {
        if (! is_string($contents)
            || $contents === ''
            || strlen($contents) > self::MAX_RESPONSE_BYTES
            || preg_match('/<!\s*ENTITY\b/i', $contents) === 1
            || ($xml && preg_match('/<!\s*DOCTYPE\b/i', $contents) === 1)) {
            return null;
        }

        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            $document->validateOnParse = false;
            $loaded = $xml
                ? $document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)
                : $document->loadHTML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);

            if ($loaded && ! $xml) {
                $unsafeNodes = (new DOMXPath($document))->query('//script | //style');
                if ($unsafeNodes !== false) {
                    foreach (iterator_to_array($unsafeNodes) as $unsafeNode) {
                        $unsafeNode->parentNode?->removeChild($unsafeNode);
                    }
                }
            }

            return $loaded ? $document : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    /** @param list<string> $names */
    private function firstChildText(DOMElement $element, array $names): string
    {
        foreach ($names as $name) {
            foreach ($element->childNodes as $child) {
                if (! $child instanceof DOMNode) {
                    continue;
                }
                if (strtolower((string) ($child->localName ?? $child->nodeName)) === $name) {
                    return (string) $child->textContent;
                }
            }
        }

        return '';
    }

    private function feedItemUrl(DOMElement $element): string
    {
        $fallback = '';
        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower($child->localName) !== 'link') {
                continue;
            }
            $href = trim($child->getAttribute('href'));
            $text = trim($child->textContent);
            $candidate = $href !== '' ? $href : $text;
            if ($candidate === '') {
                continue;
            }
            $relation = strtolower(trim($child->getAttribute('rel')));
            if ($relation === 'alternate') {
                return $candidate;
            }
            if ($relation === '' && $fallback === '') {
                $fallback = $candidate;
            }
        }

        return $fallback;
    }

    /** @param list<string> $names */
    private function firstMeaningfulExcerpt(DOMElement $element, array $names): string
    {
        foreach ($names as $name) {
            foreach ($element->childNodes as $child) {
                if (! $child instanceof DOMElement || strtolower($child->localName) !== $name) {
                    continue;
                }
                $excerpt = $this->newsExcerpt($child->textContent);
                if ($excerpt !== '') {
                    return $excerpt;
                }
            }
        }

        return '';
    }

    private function externalDate(string $value): ?CarbonImmutable
    {
        try {
            return trim($value) === '' ? null : CarbonImmutable::parse($value)->setTimezone('Europe/Amsterdam');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{id: string, source: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}
     */
    private function item(
        string $source,
        string $sourceId,
        string $sourceLabel,
        string $title,
        string $excerpt,
        string $url,
        CarbonImmutable $publishedAt,
    ): array {
        return [
            'id' => substr(hash('sha256', $source.'|'.$sourceId.'|'.$url.'|'.$title.'|'.$publishedAt->toIso8601String()), 0, 32),
            'source' => $source,
            'source_id' => $sourceId,
            'source_label' => $sourceLabel,
            'title' => $title,
            'excerpt' => $excerpt,
            'url' => $url,
            'published_at' => (string) ApiDateTime::dateTime($publishedAt),
        ];
    }

    private function itemDate(array $item): ?CarbonImmutable
    {
        return $this->externalDate((string) ($item['published_at'] ?? ''));
    }

    private function plainText(string $value, int $maximumLength): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = preg_replace('/<(?:script|style)\b[^>]*>.*?<\/(?:script|style)>/isu', ' ', $value) ?? '';
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        return mb_substr($value, 0, $maximumLength);
    }

    private function newsExcerpt(string $value): string
    {
        $value = $this->plainText($value, 4000);
        $value = preg_replace(
            '/\s*Lees\s+(?:verder|meer)(?:\s*[»›:\-])?(?:\s+https?:\/\/\S+)?\s*$/iu',
            '',
            $value,
        ) ?? '';

        return mb_substr(trim($value), 0, self::MAX_EXCERPT_LENGTH);
    }

    private function isOfficialArticleUrl(string $source, string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');

        return match ($source) {
            'ndt' => $host === 'nationaaldroneteam.nl' && str_starts_with($path, '/in_het_nieuws/'),
            'dronewatch' => in_array($host, ['dronewatch.nl', 'www.dronewatch.nl'], true) && $path !== '/',
            default => false,
        };
    }

    private function isSameOriginArticleUrl(string $articleUrl, string $feedUrl): bool
    {
        if (! WallboardConfiguration::hasValidTickerHttpsUrlSyntax($articleUrl)
            || ! WallboardConfiguration::hasValidTickerHttpsUrlSyntax($feedUrl)) {
            return false;
        }
        $article = parse_url($articleUrl);
        $feed = parse_url($feedUrl);

        return is_array($article)
            && is_array($feed)
            && strtolower((string) ($article['host'] ?? '')) === strtolower((string) ($feed['host'] ?? ''));
    }

    /** @return array{id: string, label: string, url: string}|null */
    private function validCustomSource(mixed $source): ?array
    {
        if (! is_array($source) || array_diff(array_keys($source), ['id', 'label', 'url']) !== []) {
            return null;
        }
        $id = trim((string) ($source['id'] ?? ''));
        $label = trim((string) ($source['label'] ?? ''));
        $url = trim((string) ($source['url'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $id) !== 1
            || in_array($id, WallboardConfiguration::NEWS_SOURCES, true)
            || $label === ''
            || mb_strlen($label) > WallboardConfiguration::MAX_NEWS_CUSTOM_SOURCE_LABEL_LENGTH
            || $label !== strip_tags($label)
            || ! WallboardConfiguration::hasValidTickerHttpsUrlSyntax($url)) {
            return null;
        }

        return ['id' => $id, 'label' => $label, 'url' => $url];
    }

    /** @param array{id: string, label: string, url: string} $source */
    private function customSourceKey(array $source): string
    {
        return hash('sha256', $source['url']);
    }

    /** @return array{host: string, ip: string}|null */
    private function validatedTarget(string $url, bool $official): ?array
    {
        if ($official) {
            $source = str_contains($url, 'nationaaldroneteam.nl/') ? 'ndt' : 'dronewatch';
            if (! in_array($url, [self::NDT_FEED_URL, self::NDT_FEED_PAGE_TWO_URL, self::DRONEWATCH_FEED_URL], true)
                && ! $this->isOfficialArticleUrl($source, $url)) {
                return null;
            }
        } elseif (! WallboardConfiguration::hasValidTickerHttpsUrlSyntax($url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $addresses = ($this->dnsResolver)($host);
        $addresses = array_values(array_unique(array_filter(
            $addresses,
            static fn (mixed $address): bool => is_string($address) && $address !== '',
        )));
        if ($addresses === [] || count($addresses) > 16) {
            return null;
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                return null;
            }
        }
        sort($addresses, SORT_STRING);

        return ['host' => $host, 'ip' => $addresses[0]];
    }

    /** @return list<string> */
    private function resolveDns(string $host): array
    {
        if (! function_exists('dns_get_record')) {
            return [];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (is_string($record['ip'] ?? null)) {
                $addresses[] = $record['ip'];
            }
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (self::DENIED_NETWORKS as $network) {
            if ($this->ipInNetwork($ip, $network)) {
                return false;
            }
        }

        return true;
    }

    private function ipInNetwork(string $ip, string $network): bool
    {
        [$networkAddress, $prefixText] = explode('/', $network, 2);
        $packedIp = @inet_pton($ip);
        $packedNetwork = @inet_pton($networkAddress);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }
        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    /** @param list<string> $allowedContentTypes */
    private function hasContentType(?string $contentType, array $allowedContentTypes): bool
    {
        $mediaType = strtolower(trim(explode(';', (string) $contentType, 2)[0]));

        return in_array($mediaType, $allowedContentTypes, true);
    }

    private function curlResolveEntry(string $host, string $ip): string
    {
        $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return "{$host}:443:{$address}";
    }

    /**
     * @return list<array{id: string, source: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>|null
     */
    private function cachedItems(string $source, mixed $payload): ?array
    {
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== self::CACHE_VERSION
            || ! is_array($payload['items'] ?? null)) {
            return null;
        }

        $items = [];
        foreach (array_slice($payload['items'], 0, self::MAX_SOURCE_ITEMS) as $item) {
            if (! is_array($item)
                || (string) ($item['source'] ?? '') !== $source
                || ! $this->isOfficialArticleUrl($source, (string) ($item['url'] ?? ''))) {
                continue;
            }
            $title = $this->plainText((string) ($item['title'] ?? ''), 240);
            $excerpt = $source === 'dronewatch'
                ? $this->newsExcerpt((string) ($item['excerpt'] ?? ''))
                : $this->plainText((string) ($item['excerpt'] ?? ''), self::MAX_EXCERPT_LENGTH);
            $publishedAt = $this->itemDate($item);
            if ($title === '' || ! $publishedAt instanceof CarbonImmutable) {
                continue;
            }
            $items[] = $this->item(
                $source,
                $source,
                self::SOURCE_LABELS[$source],
                $title,
                $excerpt,
                (string) $item['url'],
                $publishedAt,
            );
        }

        return $items;
    }

    /**
     * @param  array{id: string, label: string, url: string}  $source
     * @return list<array{id: string, source: string, source_id: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>|null
     */
    private function cachedCustomItems(array $source, mixed $payload): ?array
    {
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== self::CACHE_VERSION
            || ! is_array($payload['items'] ?? null)) {
            return null;
        }

        $items = [];
        foreach (array_slice($payload['items'], 0, self::MAX_SOURCE_ITEMS) as $item) {
            if (! is_array($item)
                || (string) ($item['source'] ?? '') !== 'custom'
                || ! $this->isSameOriginArticleUrl((string) ($item['url'] ?? ''), $source['url'])) {
                continue;
            }
            $title = $this->plainText((string) ($item['title'] ?? ''), 240);
            $excerpt = $this->newsExcerpt((string) ($item['excerpt'] ?? ''));
            $publishedAt = $this->itemDate($item);
            if ($title === '' || ! $publishedAt instanceof CarbonImmutable) {
                continue;
            }
            $items[] = $this->item(
                'custom',
                $source['id'],
                $source['label'],
                $title,
                $excerpt,
                (string) $item['url'],
                $publishedAt,
            );
        }

        return $items;
    }

    /**
     * @param  list<array{id: string, source: string, source_id: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>  $items
     * @param  array{id: string, label: string, url: string}  $source
     * @return list<array{id: string, source: string, source_id: string, source_label: string, title: string, excerpt: string, url: string, published_at: string}>
     */
    private function relabelCustomItems(array $items, array $source): array
    {
        return collect($items)
            ->map(function (array $item) use ($source): ?array {
                $publishedAt = $this->itemDate($item);
                if (! $publishedAt instanceof CarbonImmutable) {
                    return null;
                }

                return $this->item(
                    'custom',
                    $source['id'],
                    $source['label'],
                    $this->plainText((string) ($item['title'] ?? ''), 240),
                    $this->newsExcerpt((string) ($item['excerpt'] ?? '')),
                    (string) ($item['url'] ?? $source['url']),
                    $publishedAt,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function sourceFreshKey(string $source): string
    {
        return 'wallboard:news:source:fresh:v'.self::CACHE_VERSION.':'.$source;
    }

    private function sourceStaleKey(string $source): string
    {
        return 'wallboard:news:source:stale:v'.self::CACHE_VERSION.':'.$source;
    }

    private function sourceFailureKey(string $source): string
    {
        return 'wallboard:news:source:failure:v'.self::CACHE_VERSION.':'.$source;
    }

    private function detailFreshKey(string $url): string
    {
        return 'wallboard:news:detail:fresh:v'.self::CACHE_VERSION.':'.hash('sha256', $url);
    }

    private function detailStaleKey(string $url): string
    {
        return 'wallboard:news:detail:stale:v'.self::CACHE_VERSION.':'.hash('sha256', $url);
    }

    private function detailFailureKey(string $url): string
    {
        return 'wallboard:news:detail:failure:v'.self::CACHE_VERSION.':'.hash('sha256', $url);
    }
}
