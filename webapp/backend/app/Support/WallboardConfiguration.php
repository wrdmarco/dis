<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class WallboardConfiguration
{
    public const DEFAULT_PAGE_ID = 'map';

    /** @var list<string> */
    public const PAGE_TYPES = ['map', 'incident_list', 'summary', 'message', 'news', 'video'];

    public const MAX_VIDEO_URL_LENGTH = 2048;

    /** @var list<string> */
    public const NEWS_SOURCES = ['ndt', 'dronewatch'];

    public const DEFAULT_NEWS_MAX_ITEMS = 6;

    public const MIN_NEWS_MAX_ITEMS = 1;

    public const MAX_NEWS_MAX_ITEMS = 12;

    public const DEFAULT_NEWS_ITEM_DURATION_SECONDS = 12;

    public const MIN_NEWS_ITEM_DURATION_SECONDS = 5;

    public const MAX_NEWS_ITEM_DURATION_SECONDS = 300;

    public const MAX_NEWS_CUSTOM_SOURCES = 8;

    public const MAX_NEWS_CUSTOM_SOURCE_ID_LENGTH = 64;

    public const MAX_NEWS_CUSTOM_SOURCE_LABEL_LENGTH = 80;

    public const MAX_NEWS_CUSTOM_SOURCE_URL_LENGTH = 2048;

    /** @var list<string> */
    public const TICKER_SOURCE_TYPES = ['internal', 'rss'];

    /** @var list<string> */
    public const FOCUS_KINDS = ['preannouncement', 'real_alarm', 'test_alarm'];

    public const MAX_TICKER_SOURCES = 10;

    public const MAX_TICKER_SOURCE_ID_LENGTH = 64;

    public const MAX_TICKER_LABEL_LENGTH = 80;

    public const MAX_TICKER_INTERNAL_TEXT_LENGTH = 500;

    public const MAX_TICKER_URL_LENGTH = 2048;

    public const DEFAULT_TICKER_RSS_MAX_ITEMS = 8;

    public const MIN_TICKER_RSS_MAX_ITEMS = 1;

    public const MAX_TICKER_RSS_MAX_ITEMS = 8;

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'theme' => 'dark',
            'refresh_seconds' => 10,
            'rotation_enabled' => true,
            'pages' => [
                [
                    'id' => self::DEFAULT_PAGE_ID,
                    'name' => 'Kaart',
                    'type' => 'map',
                    'duration_seconds' => 30,
                    'options' => [],
                ],
            ],
            'focus' => [
                'preannouncement' => [
                    'enabled' => true,
                    'duration_seconds' => 120,
                    'show_response_feed' => true,
                ],
                'real_alarm' => [
                    'enabled' => true,
                    'duration_seconds' => 30,
                    'show_response_feed' => true,
                ],
                'test_alarm' => [
                    'enabled' => true,
                    'duration_seconds' => 300,
                    'show_response_feed' => true,
                ],
            ],
            'incident_override' => [
                'enabled' => false,
                'page_id' => self::DEFAULT_PAGE_ID,
            ],
            'ticker' => [
                'enabled' => false,
                'sources' => [],
            ],
            'map' => [
                'show_active_incidents' => true,
                'show_test_incidents' => false,
                'show_live_locations' => true,
                'show_routes' => true,
                'show_command_centers' => true,
                'show_historical_incidents' => false,
                'show_summary' => true,
                'show_incident_list' => true,
                'show_route_legend' => true,
                'auto_fit' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public static function normalize(array $input, array $base = []): array
    {
        $normalized = array_replace_recursive(self::defaults(), $base, $input);
        // Test alerts are transient reachability signals, never persistent wallboard incidents.
        // Keep accepting the legacy key so existing playlists remain readable, but force it off.
        $normalized['map']['show_test_incidents'] = false;

        // Numeric arrays must be replaced rather than recursively merged. Otherwise
        // removing or reordering pages can silently retain entries from the old list.
        if (array_key_exists('pages', $input)) {
            $normalized['pages'] = array_values((array) $input['pages']);
        } elseif (array_key_exists('pages', $base)) {
            $normalized['pages'] = array_values((array) $base['pages']);
        }
        if (array_key_exists('ticker', $input) && is_array($input['ticker']) && array_key_exists('sources', $input['ticker'])) {
            $normalized['ticker']['sources'] = array_values((array) $input['ticker']['sources']);
        } elseif (array_key_exists('ticker', $base) && is_array($base['ticker']) && array_key_exists('sources', $base['ticker'])) {
            $normalized['ticker']['sources'] = array_values((array) $base['ticker']['sources']);
        }

        $pages = array_values((array) ($normalized['pages'] ?? []));
        if ($pages === []) {
            throw ValidationException::withMessages([
                'configuration.pages' => ['Een wallboard heeft minimaal een pagina nodig.'],
            ]);
        }
        if (count($pages) > 20) {
            throw ValidationException::withMessages([
                'configuration.pages' => ['Een wallboard kan maximaal twintig pagina\'s bevatten.'],
            ]);
        }

        $pageIds = [];
        foreach ($pages as $index => $page) {
            if (! is_array($page)) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}" => ['De wallboardpagina is ongeldig.'],
                ]);
            }

            $pageId = (string) ($page['id'] ?? '');
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $pageId) !== 1 || isset($pageIds[$pageId])) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.id" => ['Elke wallboardpagina heeft een unieke pagina-ID nodig.'],
                ]);
            }
            $pageIds[$pageId] = true;

            $name = trim((string) ($page['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.name" => ['De paginanaam is verplicht en mag maximaal 120 tekens bevatten.'],
                ]);
            }

            $type = (string) ($page['type'] ?? '');
            if (! in_array($type, self::PAGE_TYPES, true)) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.type" => ['Dit wallboardpaginatype wordt niet ondersteund.'],
                ]);
            }

            $options = (array) ($page['options'] ?? []);
            $durationSeconds = (int) ($page['duration_seconds'] ?? 0);
            if ($durationSeconds < 5 || $durationSeconds > 3600) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.duration_seconds" => ['De zichtduur moet tussen 5 en 3600 seconden liggen.'],
                ]);
            }
            $allowedOptionKeys = match ($type) {
                'message' => ['body', 'content'],
                'incident_list', 'summary' => ['show_test_incidents'],
                'news' => ['sources', 'custom_sources', 'max_items', 'item_duration_seconds'],
                'video' => ['url'],
                default => [],
            };
            if (array_diff(array_keys($options), $allowedOptionKeys) !== []) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.options" => ['Deze pagina bevat opties die niet bij het gekozen paginatype horen.'],
                ]);
            }
            if ($type === 'message') {
                $options = WallboardRichText::normalizeOptions(
                    $options,
                    "configuration.pages.{$index}.options",
                );
            } elseif ($type === 'video') {
                $url = is_string($options['url'] ?? null)
                    ? self::normalizeVideoUrl($options['url'])
                    : null;
                if ($url === null) {
                    throw ValidationException::withMessages([
                        "configuration.pages.{$index}.options.url" => ['Een videopagina heeft een geldige HTTPS-URL van YouTube of Vimeo nodig.'],
                    ]);
                }
                $options = ['url' => $url];
            } elseif ($type === 'news') {
                $sources = array_values((array) ($options['sources'] ?? self::NEWS_SOURCES));
                if (count($sources) > count(self::NEWS_SOURCES)) {
                    throw ValidationException::withMessages([
                        "configuration.pages.{$index}.options.sources" => ['Kies alleen unieke, ondersteunde nieuwsbronnen.'],
                    ]);
                }
                $seenSources = [];
                foreach ($sources as $sourceIndex => $source) {
                    if (! is_string($source) || ! in_array($source, self::NEWS_SOURCES, true)) {
                        throw ValidationException::withMessages([
                            "configuration.pages.{$index}.options.sources.{$sourceIndex}" => ['Deze nieuwsbron wordt niet ondersteund.'],
                        ]);
                    }
                    if (isset($seenSources[$source])) {
                        throw ValidationException::withMessages([
                            "configuration.pages.{$index}.options.sources.{$sourceIndex}" => ['Elke nieuwsbron mag maar een keer worden gekozen.'],
                        ]);
                    }
                    $seenSources[$source] = true;
                }

                $customSources = array_values((array) ($options['custom_sources'] ?? []));
                if (count($customSources) > self::MAX_NEWS_CUSTOM_SOURCES) {
                    throw ValidationException::withMessages([
                        "configuration.pages.{$index}.options.custom_sources" => ['Een nieuwspagina kan maximaal acht eigen RSS-bronnen bevatten.'],
                    ]);
                }
                $customSourceIds = [];
                $customSourceUrls = [];
                foreach ($customSources as $customSourceIndex => $customSource) {
                    if (! is_array($customSource)
                        || array_diff(array_keys($customSource), ['id', 'label', 'url']) !== []) {
                        throw ValidationException::withMessages([
                            "configuration.pages.{$index}.options.custom_sources.{$customSourceIndex}" => ['Een eigen RSS-bron bevat alleen id, label en url.'],
                        ]);
                    }

                    $sourceId = trim((string) ($customSource['id'] ?? ''));
                    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $sourceId) !== 1
                        || in_array($sourceId, self::NEWS_SOURCES, true)
                        || isset($customSourceIds[$sourceId])) {
                        throw ValidationException::withMessages([
                            "configuration.pages.{$index}.options.custom_sources.{$customSourceIndex}.id" => ['Elke eigen RSS-bron heeft een unieke veilige bron-ID nodig.'],
                        ]);
                    }
                    $customSourceIds[$sourceId] = true;

                    $label = trim((string) ($customSource['label'] ?? ''));
                    if ($label === ''
                        || mb_strlen($label) > self::MAX_NEWS_CUSTOM_SOURCE_LABEL_LENGTH
                        || $label !== strip_tags($label)) {
                        throw ValidationException::withMessages([
                            "configuration.pages.{$index}.options.custom_sources.{$customSourceIndex}.label" => ['Het bronlabel is platte tekst van maximaal 80 tekens.'],
                        ]);
                    }

                    $url = trim((string) ($customSource['url'] ?? ''));
                    if (! self::hasValidTickerHttpsUrlSyntax($url) || isset($customSourceUrls[$url])) {
                        throw ValidationException::withMessages([
                            "configuration.pages.{$index}.options.custom_sources.{$customSourceIndex}.url" => ['Elke eigen RSS-bron heeft een unieke openbare HTTPS-URL op poort 443 nodig.'],
                        ]);
                    }
                    $customSourceUrls[$url] = true;
                    $customSources[$customSourceIndex] = [
                        'id' => $sourceId,
                        'label' => $label,
                        'url' => $url,
                    ];
                }

                if ($sources === [] && $customSources === []) {
                    throw ValidationException::withMessages([
                        "configuration.pages.{$index}.options.sources" => ['Kies minimaal een ingebouwde of eigen RSS-nieuwsbron.'],
                    ]);
                }

                $maximumItems = $options['max_items'] ?? self::DEFAULT_NEWS_MAX_ITEMS;
                if (! is_int($maximumItems)
                    || $maximumItems < self::MIN_NEWS_MAX_ITEMS
                    || $maximumItems > self::MAX_NEWS_MAX_ITEMS) {
                    throw ValidationException::withMessages([
                        "configuration.pages.{$index}.options.max_items" => ['Het aantal nieuwsberichten moet een geheel getal tussen 1 en 12 zijn.'],
                    ]);
                }

                $itemDurationSeconds = $options['item_duration_seconds'] ?? self::DEFAULT_NEWS_ITEM_DURATION_SECONDS;
                if (! is_int($itemDurationSeconds)
                    || $itemDurationSeconds < self::MIN_NEWS_ITEM_DURATION_SECONDS
                    || $itemDurationSeconds > self::MAX_NEWS_ITEM_DURATION_SECONDS) {
                    throw ValidationException::withMessages([
                        "configuration.pages.{$index}.options.item_duration_seconds" => ['De zichtduur per nieuwsbericht moet een geheel getal tussen 5 en 300 seconden zijn.'],
                    ]);
                }

                $options = [
                    'sources' => $sources,
                    'custom_sources' => $customSources,
                    'max_items' => $maximumItems,
                    'item_duration_seconds' => $itemDurationSeconds,
                ];
                $durationSeconds = $maximumItems * $itemDurationSeconds;
            } elseif (in_array($type, ['incident_list', 'summary'], true)) {
                // The legacy option is accepted above for lossless upgrades, but no longer has effect.
                $options = [];
            } else {
                $options = [];
            }

            $pages[$index] = [
                'id' => $pageId,
                'name' => $name,
                'type' => $type,
                'duration_seconds' => $durationSeconds,
                'options' => $options,
            ];
        }

        $normalized['pages'] = $pages;
        $normalized['focus'] = self::normalizeFocus((array) ($normalized['focus'] ?? []));

        $override = (array) ($normalized['incident_override'] ?? []);
        $overridePageId = (string) ($override['page_id'] ?? '');
        if (! isset($pageIds[$overridePageId])) {
            if (($override['enabled'] ?? false) === true) {
                throw ValidationException::withMessages([
                    'configuration.incident_override.page_id' => ['De incidentpagina moet naar een bestaande wallboardpagina verwijzen.'],
                ]);
            }

            $overridePageId = (string) $pages[0]['id'];
        }
        $normalized['incident_override'] = [
            'enabled' => (bool) ($override['enabled'] ?? false),
            'page_id' => $overridePageId,
        ];

        $normalized['ticker'] = self::normalizeTicker((array) ($normalized['ticker'] ?? []));

        if (($normalized['map']['show_routes'] ?? false) === true
            && ($normalized['map']['show_live_locations'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'configuration.map.show_routes' => ['Routes vereisen dat live locaties zichtbaar zijn.'],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $focus
     * @return array<string, array{enabled: bool, duration_seconds: int, show_response_feed: bool}>
     */
    private static function normalizeFocus(array $focus): array
    {
        if (array_diff(array_keys($focus), self::FOCUS_KINDS) !== []) {
            throw ValidationException::withMessages([
                'configuration.focus' => ['De focusconfiguratie bevat niet-ondersteunde instellingen.'],
            ]);
        }

        $normalized = [];
        foreach (self::FOCUS_KINDS as $kind) {
            $settings = $focus[$kind] ?? null;
            if (! is_array($settings)
                || array_diff(array_keys($settings), ['enabled', 'duration_seconds', 'show_response_feed']) !== []) {
                throw ValidationException::withMessages([
                    "configuration.focus.{$kind}" => ['Deze focusfase bevat niet-ondersteunde instellingen.'],
                ]);
            }

            $durationSeconds = (int) ($settings['duration_seconds'] ?? 0);
            if ($durationSeconds < 5 || $durationSeconds > 3600) {
                throw ValidationException::withMessages([
                    "configuration.focus.{$kind}.duration_seconds" => ['De focusduur moet tussen 5 en 3600 seconden liggen.'],
                ]);
            }

            $normalized[$kind] = [
                'enabled' => (bool) ($settings['enabled'] ?? false),
                'duration_seconds' => $durationSeconds,
                'show_response_feed' => (bool) ($settings['show_response_feed'] ?? false),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $ticker
     * @return array{enabled: bool, sources: list<array<string, string|int>>}
     */
    private static function normalizeTicker(array $ticker): array
    {
        if (array_diff(array_keys($ticker), ['enabled', 'sources']) !== []) {
            throw ValidationException::withMessages([
                'configuration.ticker' => ['De ticker bevat niet-ondersteunde instellingen.'],
            ]);
        }

        $sources = array_values((array) ($ticker['sources'] ?? []));
        if (count($sources) > self::MAX_TICKER_SOURCES) {
            throw ValidationException::withMessages([
                'configuration.ticker.sources' => ['Een wallboardticker kan maximaal tien bronnen bevatten.'],
            ]);
        }

        $sourceIds = [];
        foreach ($sources as $index => $source) {
            if (! is_array($source)) {
                throw ValidationException::withMessages([
                    "configuration.ticker.sources.{$index}" => ['De tickerbron is ongeldig.'],
                ]);
            }

            $sourceId = trim((string) ($source['id'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $sourceId) !== 1 || isset($sourceIds[$sourceId])) {
                throw ValidationException::withMessages([
                    "configuration.ticker.sources.{$index}.id" => ['Elke tickerbron heeft een unieke veilige bron-ID nodig.'],
                ]);
            }
            $sourceIds[$sourceId] = true;

            $type = (string) ($source['type'] ?? '');
            if (! in_array($type, self::TICKER_SOURCE_TYPES, true)) {
                throw ValidationException::withMessages([
                    "configuration.ticker.sources.{$index}.type" => ['Dit tickerbrontype wordt niet ondersteund.'],
                ]);
            }

            $label = trim((string) ($source['label'] ?? ''));
            if ($label === ''
                || mb_strlen($label) > self::MAX_TICKER_LABEL_LENGTH
                || $label !== strip_tags($label)) {
                throw ValidationException::withMessages([
                    "configuration.ticker.sources.{$index}.label" => ['Het bronlabel is platte tekst van maximaal 80 tekens.'],
                ]);
            }

            $allowedKeys = $type === 'internal'
                ? ['id', 'type', 'label', 'text']
                : ['id', 'type', 'label', 'url', 'max_items'];
            if (array_diff(array_keys($source), $allowedKeys) !== []) {
                throw ValidationException::withMessages([
                    "configuration.ticker.sources.{$index}" => ['Deze tickerbron bevat velden die niet bij het gekozen type horen.'],
                ]);
            }

            if ($type === 'internal') {
                $text = trim((string) ($source['text'] ?? ''));
                if ($text === ''
                    || mb_strlen($text) > self::MAX_TICKER_INTERNAL_TEXT_LENGTH
                    || $text !== strip_tags($text)) {
                    throw ValidationException::withMessages([
                        "configuration.ticker.sources.{$index}.text" => ['Een intern tickerbericht is platte tekst van maximaal 500 tekens.'],
                    ]);
                }

                $sources[$index] = [
                    'id' => $sourceId,
                    'type' => $type,
                    'label' => $label,
                    'text' => $text,
                ];

                continue;
            }

            $url = trim((string) ($source['url'] ?? ''));
            if (! self::hasValidTickerHttpsUrlSyntax($url)) {
                throw ValidationException::withMessages([
                    "configuration.ticker.sources.{$index}.url" => ['Een RSS-bron heeft een geldige openbare HTTPS-URL op poort 443 nodig.'],
                ]);
            }

            $maxItems = $source['max_items'] ?? self::DEFAULT_TICKER_RSS_MAX_ITEMS;
            if (! is_int($maxItems)
                || $maxItems < self::MIN_TICKER_RSS_MAX_ITEMS
                || $maxItems > self::MAX_TICKER_RSS_MAX_ITEMS) {
                throw ValidationException::withMessages([
                    "configuration.ticker.sources.{$index}.max_items" => ['Het aantal RSS-items moet een geheel getal tussen 1 en 8 zijn.'],
                ]);
            }

            $sources[$index] = [
                'id' => $sourceId,
                'type' => $type,
                'label' => $label,
                'url' => $url,
                'max_items' => $maxItems,
            ];
        }

        return [
            'enabled' => (bool) ($ticker['enabled'] ?? false),
            'sources' => $sources,
        ];
    }

    public static function hasValidTickerHttpsUrlSyntax(string $url): bool
    {
        if ($url === ''
            || strlen($url) > self::MAX_TICKER_URL_LENGTH
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || str_ends_with($host, '.') || strlen($host) > 253 || str_contains($host, '%')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        if (preg_match('/^[0-9.]+$/', $host) === 1) {
            return false;
        }

        return preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
            $host,
        ) === 1;
    }

    public static function normalizeVideoUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === ''
            || strlen($url) > self::MAX_VIDEO_URL_LENGTH
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = (string) ($parts['path'] ?? '');
        $videoId = null;

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if ($path === '/watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $videoId = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/embed/([A-Za-z0-9_-]{11})/?$#D', $path, $matches) === 1) {
                $videoId = $matches[1];
            } elseif (preg_match('#^/shorts/([A-Za-z0-9_-]{11})/?$#D', $path, $matches) === 1) {
                $videoId = $matches[1];
            }

            return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId) === 1
                ? 'https://www.youtube.com/embed/'.$videoId
                : null;
        }

        if ($host === 'youtu.be') {
            return preg_match('#^/([A-Za-z0-9_-]{11})/?$#D', $path, $matches) === 1
                ? 'https://www.youtube.com/embed/'.$matches[1]
                : null;
        }

        if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true)) {
            $pattern = '#^/([1-9][0-9]{0,11})/?$#D';
        } elseif ($host === 'player.vimeo.com') {
            $pattern = '#^/video/([1-9][0-9]{0,11})/?$#D';
        } else {
            return null;
        }

        return preg_match($pattern, $path, $matches) === 1
            ? 'https://player.vimeo.com/video/'.$matches[1]
            : null;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function hasPage(array $configuration, string $pageId): bool
    {
        foreach ((array) ($configuration['pages'] ?? []) as $page) {
            if (is_array($page) && (string) ($page['id'] ?? '') === $pageId) {
                return true;
            }
        }

        return false;
    }
}
