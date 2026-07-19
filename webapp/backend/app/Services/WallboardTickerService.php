<?php

namespace App\Services;

use App\Support\WallboardConfiguration;
use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WallboardTickerService
{
    public const MAX_SOURCES = WallboardConfiguration::MAX_TICKER_SOURCES;

    public const MAX_SOURCE_ID_LENGTH = WallboardConfiguration::MAX_TICKER_SOURCE_ID_LENGTH;

    public const MAX_LABEL_LENGTH = WallboardConfiguration::MAX_TICKER_LABEL_LENGTH;

    public const MAX_INTERNAL_TEXT_LENGTH = WallboardConfiguration::MAX_TICKER_INTERNAL_TEXT_LENGTH;

    public const MAX_URL_LENGTH = WallboardConfiguration::MAX_TICKER_URL_LENGTH;

    public const MAX_RSS_TEXT_LENGTH = 300;

    public const MAX_RSS_ITEMS_PER_SOURCE = 8;

    public const MAX_ITEMS = 50;

    public const MAX_RESPONSE_BYTES = 262_144;

    private const MAX_FEED_NODES = 50;

    /** @var list<string> */
    private const XML_CONTENT_TYPES = [
        'application/atom+xml',
        'application/rdf+xml',
        'application/rss+xml',
        'application/xml',
        'text/xml',
    ];

    /**
     * Extra deny ranges supplement PHP's NO_PRIV_RANGE and NO_RES_RANGE flags.
     * Documentation, benchmarking, carrier NAT, multicast and transition ranges
     * are deliberately not accepted as ticker destinations.
     *
     * @var list<string>
     */
    private const DENIED_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
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
     * Resolve already-normalized ticker configuration to display-safe text.
     * Every failure is represented by an empty source result. Raw transport,
     * DNS and XML errors are never returned or logged with a configured URL.
     *
     * @param  array<string, mixed>  $configuration
     * @return list<array{source_id: string, source_type: string, source_label: string, text: string}>
     */
    public function items(array $configuration): array
    {
        if (($configuration['enabled'] ?? false) !== true) {
            return [];
        }

        $sources = array_slice(array_values((array) ($configuration['sources'] ?? [])), 0, self::MAX_SOURCES);
        $items = [];
        $coldFetchAvailable = true;

        foreach ($sources as $source) {
            if (! is_array($source) || count($items) >= self::MAX_ITEMS) {
                continue;
            }

            $sourceId = trim((string) ($source['id'] ?? ''));
            $type = (string) ($source['type'] ?? '');
            $label = $this->plainText((string) ($source['label'] ?? ''), self::MAX_LABEL_LENGTH);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $sourceId) !== 1 || $label === '') {
                continue;
            }

            if ($type === 'internal') {
                $text = $this->plainText((string) ($source['text'] ?? ''), self::MAX_INTERNAL_TEXT_LENGTH);
                if ($text !== '') {
                    $items[] = $this->item($sourceId, $type, $label, $text);
                }

                continue;
            }

            if ($type !== 'rss') {
                continue;
            }

            foreach ($this->rssItems((string) ($source['url'] ?? ''), $coldFetchAvailable) as $text) {
                $items[] = $this->item($sourceId, $type, $label, $text);
                if (count($items) >= self::MAX_ITEMS) {
                    break 2;
                }
            }
        }

        return $items;
    }

    /**
     * Lightweight syntax check for the administration contract. DNS is still
     * resolved and pinned immediately before every actual fetch.
     */
    public static function hasValidHttpsUrlSyntax(string $url): bool
    {
        return WallboardConfiguration::hasValidTickerHttpsUrlSyntax($url);
    }

    /** @return list<string> */
    private function rssItems(string $url, bool &$coldFetchAvailable): array
    {
        if (! self::hasValidHttpsUrlSyntax($url)) {
            return [];
        }

        $cacheKey = 'wallboard:ticker:rss:v1:'.hash('sha256', $url);

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ($cached['version'] ?? null) === 1 && is_array($cached['items'] ?? null)) {
                return $this->cachedTexts($cached['items']);
            }

            // A full state request may synchronously warm at most one feed. All
            // cached feeds are still returned, while remaining cold feeds are
            // left for later refreshes so latency is bounded by one fetch.
            if (! $coldFetchAvailable) {
                return [];
            }
            $coldFetchAvailable = false;

            $fetched = $this->fetchRss($url);
            $items = $fetched ?? [];
            Cache::put(
                $cacheKey,
                ['version' => 1, 'items' => $items],
                now()->addSeconds($fetched === null ? $this->failureCacheSeconds() : $this->successCacheSeconds()),
            );

            return $items;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string>|null */
    private function fetchRss(string $url): ?array
    {
        try {
            $target = $this->validatedTarget($url);
            if ($target === null || ! defined('CURLOPT_RESOLVE') || ! defined('CURLOPT_PROXY')) {
                return null;
            }

            $curlOptions = [
                CURLOPT_RESOLVE => [$this->curlResolveEntry($target['host'], $target['ip'])],
                CURLOPT_PROXY => '',
            ];
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }

            $response = Http::accept(implode(', ', self::XML_CONTENT_TYPES))
                ->withHeaders([
                    'Accept-Encoding' => 'identity',
                    'User-Agent' => 'DIS-Wallboard-Ticker/1.0',
                ])
                ->connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->timeoutSeconds())
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
                            throw new \RuntimeException('Ticker response is too large.');
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
                            throw new \RuntimeException('Ticker response is too large.');
                        }
                    },
                ])
                ->get($url);

            // Redirects are intentionally not followed. This prevents a public
            // feed from redirecting a later request to a private destination.
            if ($response->status() !== 200 || ! $this->hasXmlContentType($response->header('Content-Type'))) {
                return null;
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
                return null;
            }

            return $this->parseFeed($body);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{host: string, ip: string}|null
     */
    private function validatedTarget(string $url): ?array
    {
        if (! self::hasValidHttpsUrlSyntax($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $host = self::hostWithoutIpv6Brackets($parts['host']);
        $literalIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $literalIp ? [$host] : ($this->dnsResolver)($host);
        $addresses = array_values(array_unique(array_filter(
            $addresses,
            static fn (mixed $address): bool => is_string($address) && $address !== '',
        )));

        // Reject the complete hostname if any answer is private/special. This
        // closes mixed-answer and DNS-rebinding paths instead of choosing the
        // one answer that happens to look safe.
        if ($addresses === [] || count($addresses) > 16) {
            return null;
        }
        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
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

    private static function isPublicIp(string $ip): bool
    {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        foreach (self::DENIED_NETWORKS as $network) {
            if (self::ipInNetwork($ip, $network)) {
                return false;
            }
        }

        return true;
    }

    private static function ipInNetwork(string $ip, string $network): bool
    {
        [$networkAddress, $prefixText] = explode('/', $network, 2);
        $packedIp = @inet_pton($ip);
        $packedNetwork = @inet_pton($networkAddress);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $maxBits = strlen($packedIp) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

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

    /** @return list<string>|null */
    private function parseFeed(string $xml): ?array
    {
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            return null;
        }

        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            $document->validateOnParse = false;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                return null;
            }

            $nodes = (new DOMXPath($document))->query('//*[local-name()="item" or local-name()="entry"]');
            if ($nodes === false) {
                return null;
            }

            $items = [];
            $seen = [];
            $inspected = 0;
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement || $inspected++ >= self::MAX_FEED_NODES) {
                    break;
                }

                $text = $this->firstChildText($node, ['title'])
                    ?? $this->firstChildText($node, ['description', 'summary', 'content', 'encoded']);
                $text = $this->plainText($text ?? '', self::MAX_RSS_TEXT_LENGTH);
                $key = mb_strtolower($text);
                if ($text === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $items[] = $text;
                if (count($items) >= self::MAX_RSS_ITEMS_PER_SOURCE) {
                    break;
                }
            }

            return $items;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function firstChildText(DOMElement $element, array $names): ?string
    {
        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMNode) {
                continue;
            }

            $localName = strtolower((string) ($child->localName ?? $child->nodeName));
            if (in_array($localName, $names, true)) {
                return $child->textContent;
            }
        }

        return null;
    }

    private function plainText(string $value, int $maximumLength): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        return mb_substr($value, 0, $maximumLength);
    }

    private function hasXmlContentType(?string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', (string) $contentType, 2)[0]));

        return in_array($mediaType, self::XML_CONTENT_TYPES, true);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<string>
     */
    private function cachedTexts(array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => is_string($item))
            ->map(fn (string $item): string => $this->plainText($item, self::MAX_RSS_TEXT_LENGTH))
            ->filter()
            ->take(self::MAX_RSS_ITEMS_PER_SOURCE)
            ->values()
            ->all();
    }

    /**
     * @return array{source_id: string, source_type: string, source_label: string, text: string}
     */
    private function item(string $sourceId, string $type, string $label, string $text): array
    {
        return [
            'source_id' => $sourceId,
            'source_type' => $type,
            'source_label' => $label,
            'text' => $text,
        ];
    }

    private function curlResolveEntry(string $host, string $ip): string
    {
        $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return "{$host}:443:{$address}";
    }

    private static function hostWithoutIpv6Brackets(string $host): string
    {
        return strtolower(trim($host, '[]'));
    }

    private function connectTimeoutSeconds(): int
    {
        return min(3, max(1, (int) config('dis.wallboards.ticker_connect_timeout_seconds', 2)));
    }

    private function timeoutSeconds(): int
    {
        return min(8, max(2, (int) config('dis.wallboards.ticker_timeout_seconds', 4)));
    }

    private function successCacheSeconds(): int
    {
        return min(3600, max(60, (int) config('dis.wallboards.ticker_cache_seconds', 300)));
    }

    private function failureCacheSeconds(): int
    {
        return min(300, max(15, (int) config('dis.wallboards.ticker_failure_cache_seconds', 60)));
    }
}
