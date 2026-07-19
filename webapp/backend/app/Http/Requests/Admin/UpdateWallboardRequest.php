<?php

namespace App\Http\Requests\Admin;

use App\Models\Wallboard;
use App\Support\WallboardConfiguration;
use App\Support\WallboardRichText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

final class UpdateWallboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_config_version' => ['sometimes', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'layout' => ['sometimes', 'required', 'string', Rule::in([Wallboard::LAYOUT_FULLSCREEN_MAP])],
            'display_profile' => ['sometimes', 'required', 'string', Rule::in(Wallboard::DISPLAY_PROFILES)],
            'is_enabled' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'required', 'array:theme,refresh_seconds,rotation_enabled,page_fade_enabled,page_transition,page_transition_duration_ms,page_flip_direction,pages,focus,incident_override,ticker,map'],
            'configuration.theme' => ['sometimes', 'string', Rule::in(['dark', 'light'])],
            'configuration.refresh_seconds' => ['sometimes', 'integer', 'between:5,60'],
            'configuration.rotation_enabled' => ['sometimes', 'boolean'],
            'configuration.page_fade_enabled' => ['sometimes', 'boolean:strict'],
            'configuration.page_transition' => ['sometimes', 'string', Rule::in(WallboardConfiguration::PAGE_TRANSITIONS)],
            'configuration.page_transition_duration_ms' => ['sometimes', 'integer:strict', 'between:'.WallboardConfiguration::MIN_TRANSITION_DURATION_MS.','.WallboardConfiguration::MAX_TRANSITION_DURATION_MS],
            'configuration.page_flip_direction' => ['sometimes', 'string', Rule::in(WallboardConfiguration::FLIP_DIRECTIONS)],
            'configuration.pages' => ['sometimes', 'array', 'min:1', 'max:20'],
            'configuration.pages.*' => ['required', 'array:id,name,type,duration_seconds,transition,transition_duration_ms,flip_direction,options'],
            'configuration.pages.*.id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', 'distinct:strict'],
            'configuration.pages.*.name' => ['required', 'string', 'max:120'],
            'configuration.pages.*.type' => ['required', 'string', Rule::in(WallboardConfiguration::PAGE_TYPES)],
            'configuration.pages.*.duration_seconds' => ['required', 'integer', 'between:5,3600'],
            'configuration.pages.*.transition' => ['sometimes', 'nullable', 'string', Rule::in(WallboardConfiguration::PAGE_TRANSITIONS)],
            'configuration.pages.*.transition_duration_ms' => ['sometimes', 'nullable', 'integer:strict', 'between:'.WallboardConfiguration::MIN_TRANSITION_DURATION_MS.','.WallboardConfiguration::MAX_TRANSITION_DURATION_MS],
            'configuration.pages.*.flip_direction' => ['sometimes', 'nullable', 'string', Rule::in(WallboardConfiguration::FLIP_DIRECTIONS)],
            'configuration.pages.*.options' => ['sometimes', 'array:body,content,quotes,show_test_incidents,sources,custom_sources,max_items,item_duration_seconds,item_transition,item_transition_duration_ms,item_flip_direction,url,video_duration_seconds,media_playlist_id,location_label,latitude,longitude'],
            'configuration.pages.*.options.body' => ['sometimes', 'string', 'max:2000'],
            'configuration.pages.*.options.content' => ['sometimes', 'array:version,blocks'],
            'configuration.pages.*.options.content.version' => ['sometimes', 'integer:strict'],
            'configuration.pages.*.options.content.blocks' => ['sometimes', 'array', 'max:'.WallboardRichText::MAX_BLOCKS],
            'configuration.pages.*.options.quotes' => ['sometimes', 'array', 'min:1', 'max:'.WallboardConfiguration::MAX_QUOTES],
            'configuration.pages.*.options.quotes.*' => ['required', 'array:text,author'],
            'configuration.pages.*.options.quotes.*.text' => ['required', 'string', 'max:'.WallboardConfiguration::MAX_QUOTE_TEXT_LENGTH],
            'configuration.pages.*.options.quotes.*.author' => ['sometimes', 'nullable', 'string', 'max:'.WallboardConfiguration::MAX_QUOTE_AUTHOR_LENGTH],
            'configuration.pages.*.options.url' => ['sometimes', 'string', 'max:'.WallboardConfiguration::MAX_VIDEO_URL_LENGTH],
            'configuration.pages.*.options.video_duration_seconds' => ['sometimes', 'integer:strict', 'between:'.WallboardConfiguration::MIN_VIDEO_DURATION_SECONDS.','.WallboardConfiguration::MAX_VIDEO_DURATION_SECONDS],
            'configuration.pages.*.options.media_playlist_id' => ['sometimes', 'string', 'ulid'],
            'configuration.pages.*.options.location_label' => ['sometimes', 'string', 'max:'.WallboardConfiguration::MAX_FORECAST_LOCATION_LABEL_LENGTH],
            'configuration.pages.*.options.latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'configuration.pages.*.options.longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'configuration.pages.*.options.show_test_incidents' => ['sometimes', 'boolean'],
            'configuration.pages.*.options.sources' => ['sometimes', 'array', 'max:'.count(WallboardConfiguration::NEWS_SOURCES)],
            'configuration.pages.*.options.sources.*' => ['required', 'string', Rule::in(WallboardConfiguration::NEWS_SOURCES)],
            'configuration.pages.*.options.custom_sources' => ['sometimes', 'array', 'max:'.WallboardConfiguration::MAX_NEWS_CUSTOM_SOURCES],
            'configuration.pages.*.options.custom_sources.*' => ['required', 'array:id,label,url'],
            'configuration.pages.*.options.custom_sources.*.id' => ['required', 'string', 'max:'.WallboardConfiguration::MAX_NEWS_CUSTOM_SOURCE_ID_LENGTH, 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'configuration.pages.*.options.custom_sources.*.label' => ['required', 'string', 'max:'.WallboardConfiguration::MAX_NEWS_CUSTOM_SOURCE_LABEL_LENGTH],
            'configuration.pages.*.options.custom_sources.*.url' => ['required', 'string', 'max:'.WallboardConfiguration::MAX_NEWS_CUSTOM_SOURCE_URL_LENGTH],
            'configuration.pages.*.options.max_items' => ['sometimes', 'integer:strict', 'between:'.WallboardConfiguration::MIN_NEWS_MAX_ITEMS.','.WallboardConfiguration::MAX_NEWS_MAX_ITEMS],
            'configuration.pages.*.options.item_duration_seconds' => ['sometimes', 'integer:strict', 'between:'.WallboardConfiguration::MIN_NEWS_ITEM_DURATION_SECONDS.','.WallboardConfiguration::MAX_NEWS_ITEM_DURATION_SECONDS],
            'configuration.pages.*.options.item_transition' => ['sometimes', 'string', Rule::in(WallboardConfiguration::NEWS_ITEM_TRANSITIONS)],
            'configuration.pages.*.options.item_transition_duration_ms' => ['sometimes', 'integer:strict', 'between:'.WallboardConfiguration::MIN_TRANSITION_DURATION_MS.','.WallboardConfiguration::MAX_TRANSITION_DURATION_MS],
            'configuration.pages.*.options.item_flip_direction' => ['sometimes', 'string', Rule::in(WallboardConfiguration::FLIP_DIRECTIONS)],
            'configuration.focus' => ['sometimes', 'array:preannouncement,real_alarm,test_alarm'],
            'configuration.focus.preannouncement' => ['sometimes', 'array:enabled,duration_seconds,show_response_feed'],
            'configuration.focus.real_alarm' => ['sometimes', 'array:enabled,duration_seconds,show_response_feed'],
            'configuration.focus.test_alarm' => ['sometimes', 'array:enabled,duration_seconds,show_response_feed'],
            'configuration.focus.preannouncement.enabled' => [Rule::requiredIf(fn (): bool => $this->has('configuration.focus.preannouncement')), 'boolean'],
            'configuration.focus.preannouncement.duration_seconds' => [Rule::requiredIf(fn (): bool => $this->has('configuration.focus.preannouncement')), 'integer', 'between:5,3600'],
            'configuration.focus.preannouncement.show_response_feed' => [Rule::requiredIf(fn (): bool => $this->has('configuration.focus.preannouncement')), 'boolean'],
            'configuration.focus.real_alarm.enabled' => [Rule::requiredIf(fn (): bool => $this->has('configuration.focus.real_alarm')), 'boolean'],
            'configuration.focus.real_alarm.duration_seconds' => [Rule::requiredIf(fn (): bool => $this->has('configuration.focus.real_alarm')), 'integer', 'between:5,3600'],
            'configuration.focus.real_alarm.show_response_feed' => [Rule::requiredIf(fn (): bool => $this->has('configuration.focus.real_alarm')), 'boolean'],
            'configuration.focus.test_alarm.enabled' => [Rule::requiredIf(fn (): bool => $this->has('configuration.focus.test_alarm')), 'boolean'],
            'configuration.focus.test_alarm.duration_seconds' => [Rule::requiredIf(fn (): bool => $this->has('configuration.focus.test_alarm')), 'integer', 'between:5,3600'],
            'configuration.focus.test_alarm.show_response_feed' => [Rule::requiredIf(fn (): bool => $this->has('configuration.focus.test_alarm')), 'boolean'],
            'configuration.incident_override' => ['sometimes', 'array:enabled,page_id'],
            'configuration.incident_override.enabled' => ['sometimes', 'boolean'],
            'configuration.incident_override.page_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'configuration.ticker' => ['sometimes', 'array:enabled,sources'],
            'configuration.ticker.enabled' => ['sometimes', 'boolean'],
            'configuration.ticker.sources' => ['sometimes', 'array', 'max:'.WallboardConfiguration::MAX_TICKER_SOURCES],
            'configuration.ticker.sources.*' => ['required', 'array:id,type,label,url,text,max_items'],
            'configuration.ticker.sources.*.id' => ['required', 'string', 'max:'.WallboardConfiguration::MAX_TICKER_SOURCE_ID_LENGTH, 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', 'distinct:strict'],
            'configuration.ticker.sources.*.type' => ['required', 'string', Rule::in(WallboardConfiguration::TICKER_SOURCE_TYPES)],
            'configuration.ticker.sources.*.label' => ['required', 'string', 'max:'.WallboardConfiguration::MAX_TICKER_LABEL_LENGTH],
            'configuration.ticker.sources.*.url' => ['sometimes', 'string', 'max:'.WallboardConfiguration::MAX_TICKER_URL_LENGTH],
            'configuration.ticker.sources.*.text' => ['sometimes', 'string', 'max:'.WallboardConfiguration::MAX_TICKER_INTERNAL_TEXT_LENGTH],
            'configuration.ticker.sources.*.max_items' => ['sometimes', 'integer:strict', 'between:'.WallboardConfiguration::MIN_TICKER_RSS_MAX_ITEMS.','.WallboardConfiguration::MAX_TICKER_RSS_MAX_ITEMS],
            'configuration.map' => ['sometimes', 'array:show_active_incidents,show_test_incidents,show_live_locations,show_routes,show_command_centers,show_historical_incidents,show_summary,show_incident_list,show_route_legend,auto_fit'],
            'configuration.map.show_active_incidents' => ['sometimes', 'boolean'],
            'configuration.map.show_test_incidents' => ['sometimes', 'boolean'],
            'configuration.map.show_live_locations' => ['sometimes', 'boolean'],
            'configuration.map.show_routes' => ['sometimes', 'boolean'],
            'configuration.map.show_command_centers' => ['sometimes', 'boolean'],
            'configuration.map.show_historical_incidents' => ['sometimes', 'boolean'],
            'configuration.map.show_summary' => ['sometimes', 'boolean'],
            'configuration.map.show_incident_list' => ['sometimes', 'boolean'],
            'configuration.map.show_route_legend' => ['sometimes', 'boolean'],
            'configuration.map.auto_fit' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (($this->has('configuration') || $this->has('layout') || $this->has('display_profile'))
                && ! $this->has('expected_config_version')) {
                $validator->errors()->add(
                    'expected_config_version',
                    'De actuele configuratieversie is verplicht bij een indelingswijziging.',
                );
            }

            $this->validatePageOptions($validator);
            $this->validateTickerSources($validator);
        }];
    }

    private function validatePageOptions(Validator $validator): void
    {
        foreach ((array) $this->input('configuration.pages', []) as $index => $page) {
            if (! is_array($page)) {
                continue;
            }

            $type = (string) ($page['type'] ?? '');
            $options = is_array($page['options'] ?? null) ? $page['options'] : [];
            $allowedKeys = match ($type) {
                'message', 'safety_notice' => ['body', 'content'],
                'quote' => ['quotes'],
                'uav_forecast' => ['location_label', 'latitude', 'longitude'],
                'incident_list', 'summary' => ['show_test_incidents'],
                'news' => ['sources', 'custom_sources', 'max_items', 'item_duration_seconds', 'item_transition', 'item_transition_duration_ms', 'item_flip_direction'],
                'video' => ['url', 'video_duration_seconds'],
                'photo_carousel' => ['media_playlist_id', 'item_duration_seconds'],
                'map' => [],
                default => array_keys($options),
            };
            if (array_diff(array_keys($options), $allowedKeys) !== []) {
                $validator->errors()->add(
                    "configuration.pages.{$index}.options",
                    'Deze opties horen niet bij het gekozen paginatype.',
                );
            }
            if (in_array($type, ['message', 'safety_notice'], true)) {
                try {
                    WallboardRichText::normalizeOptions(
                        $options,
                        "configuration.pages.{$index}.options",
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            }
            if ($type === 'uav_forecast'
                && (! is_string($options['location_label'] ?? null)
                    || trim($options['location_label']) === ''
                    || ! is_numeric($options['latitude'] ?? null)
                    || ! is_numeric($options['longitude'] ?? null))) {
                $validator->errors()->add(
                    "configuration.pages.{$index}.options",
                    'Een UAV Forecast-pagina heeft een locatienaam en geldige coördinaten nodig.',
                );
            }
            if ($type === 'video'
                && (! is_string($options['url'] ?? null)
                    || WallboardConfiguration::normalizeVideoUrl($options['url']) === null)) {
                $validator->errors()->add(
                    "configuration.pages.{$index}.options.url",
                    'Een videopagina heeft een geldige HTTPS-URL van YouTube of Vimeo nodig.',
                );
            }
            if ($type === 'video'
                && (! is_int($options['video_duration_seconds'] ?? null)
                    || $options['video_duration_seconds'] < WallboardConfiguration::MIN_VIDEO_DURATION_SECONDS
                    || $options['video_duration_seconds'] > WallboardConfiguration::MAX_VIDEO_DURATION_SECONDS)) {
                $validator->errors()->add(
                    "configuration.pages.{$index}.options.video_duration_seconds",
                    'Controleer eerst de videoduur voordat u de pagina opslaat.',
                );
            }
            if ($type === 'photo_carousel'
                && (! is_string($options['media_playlist_id'] ?? null)
                    || ! Str::isUlid($options['media_playlist_id'])
                    || ! is_int($options['item_duration_seconds'] ?? null)
                    || $options['item_duration_seconds'] < 5
                    || $options['item_duration_seconds'] > 300)) {
                $validator->errors()->add(
                    "configuration.pages.{$index}.options",
                    'Selecteer een geldige fotoplaylist en een zichtduur per foto tussen 5 en 300 seconden.',
                );
            }
            if ($type === 'news') {
                $this->validateNewsSources($validator, $index, $options);
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function validateNewsSources(Validator $validator, int|string $pageIndex, array $options): void
    {
        $sources = array_key_exists('sources', $options) && is_array($options['sources'])
            ? $options['sources']
            : WallboardConfiguration::NEWS_SOURCES;
        $customSources = is_array($options['custom_sources'] ?? null) ? $options['custom_sources'] : [];
        if ($sources === [] && $customSources === []) {
            $validator->errors()->add(
                "configuration.pages.{$pageIndex}.options.sources",
                'Kies minimaal een ingebouwde of eigen RSS-nieuwsbron.',
            );
        }

        $seenBuiltIn = [];
        foreach ($sources as $sourceIndex => $source) {
            if (is_string($source) && isset($seenBuiltIn[$source])) {
                $validator->errors()->add(
                    "configuration.pages.{$pageIndex}.options.sources.{$sourceIndex}",
                    'Elke ingebouwde nieuwsbron mag maar een keer worden gekozen.',
                );
            }
            if (is_string($source)) {
                $seenBuiltIn[$source] = true;
            }
        }

        $seenIds = [];
        $seenUrls = [];
        foreach ($customSources as $sourceIndex => $source) {
            if (! is_array($source)) {
                continue;
            }
            $label = trim((string) ($source['label'] ?? ''));
            $id = trim((string) ($source['id'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));
            if (in_array($id, WallboardConfiguration::NEWS_SOURCES, true) || isset($seenIds[$id])) {
                $validator->errors()->add(
                    "configuration.pages.{$pageIndex}.options.custom_sources.{$sourceIndex}.id",
                    'Elke eigen RSS-bron heeft een unieke, niet-gereserveerde bron-ID nodig.',
                );
            }
            if ($id !== '') {
                $seenIds[$id] = true;
            }
            if (isset($seenUrls[$url])) {
                $validator->errors()->add(
                    "configuration.pages.{$pageIndex}.options.custom_sources.{$sourceIndex}.url",
                    'Elke eigen RSS-URL mag per pagina maar een keer worden gebruikt.',
                );
            }
            if ($url !== '') {
                $seenUrls[$url] = true;
            }
            if ($label !== '' && $label !== strip_tags($label)) {
                $validator->errors()->add(
                    "configuration.pages.{$pageIndex}.options.custom_sources.{$sourceIndex}.label",
                    'Het bronlabel mag alleen platte tekst bevatten.',
                );
            }
            if (! WallboardConfiguration::hasValidTickerHttpsUrlSyntax($url)) {
                $validator->errors()->add(
                    "configuration.pages.{$pageIndex}.options.custom_sources.{$sourceIndex}.url",
                    'Een eigen RSS-bron heeft een geldige openbare HTTPS-URL op poort 443 nodig.',
                );
            }
        }
    }

    private function validateTickerSources(Validator $validator): void
    {
        foreach ((array) $this->input('configuration.ticker.sources', []) as $index => $source) {
            if (! is_array($source)) {
                continue;
            }

            $type = (string) ($source['type'] ?? '');
            $label = trim((string) ($source['label'] ?? ''));
            if ($label !== '' && $label !== strip_tags($label)) {
                $validator->errors()->add(
                    "configuration.ticker.sources.{$index}.label",
                    'Het bronlabel mag alleen platte tekst bevatten.',
                );
            }

            if ($type === 'internal') {
                if (array_diff(array_keys($source), ['id', 'type', 'label', 'text']) !== []) {
                    $validator->errors()->add(
                        "configuration.ticker.sources.{$index}",
                        'Een interne tickerbron mag alleen id, type, label en text bevatten.',
                    );
                }

                $text = trim((string) ($source['text'] ?? ''));
                if ($text === '') {
                    $validator->errors()->add(
                        "configuration.ticker.sources.{$index}.text",
                        'Een interne tickerbron heeft tekst nodig.',
                    );
                } elseif ($text !== strip_tags($text)) {
                    $validator->errors()->add(
                        "configuration.ticker.sources.{$index}.text",
                        'Een intern tickerbericht mag alleen platte tekst bevatten.',
                    );
                }

                continue;
            }

            if ($type === 'rss') {
                if (array_diff(array_keys($source), ['id', 'type', 'label', 'url', 'max_items']) !== []) {
                    $validator->errors()->add(
                        "configuration.ticker.sources.{$index}",
                        'Een RSS-tickerbron mag alleen id, type, label, url en max_items bevatten.',
                    );
                }

                $url = trim((string) ($source['url'] ?? ''));
                if (! WallboardConfiguration::hasValidTickerHttpsUrlSyntax($url)) {
                    $validator->errors()->add(
                        "configuration.ticker.sources.{$index}.url",
                        'Een RSS-bron heeft een geldige openbare HTTPS-URL op poort 443 nodig.',
                    );
                }
            }
        }
    }
}
