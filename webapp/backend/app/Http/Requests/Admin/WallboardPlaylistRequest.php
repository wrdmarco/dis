<?php

namespace App\Http\Requests\Admin;

use App\Models\WallboardPlaylist;
use App\Support\WallboardConfiguration;
use App\Support\WallboardRichText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

abstract class WallboardPlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function configurationRules(string $presence): array
    {
        return [
            'configuration' => [$presence, 'array:theme,refresh_seconds,rotation_enabled,page_fade_enabled,page_transition,page_transition_duration_ms,page_flip_direction,pages,focus,incident_override,ticker,map'],
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
            if ($validator->errors()->isNotEmpty() || ! $this->has('configuration')) {
                return;
            }

            foreach ((array) $this->input('configuration.pages', []) as $index => $page) {
                if (! is_array($page) || ($page['type'] ?? null) !== 'video') {
                    continue;
                }
                $options = is_array($page['options'] ?? null) ? $page['options'] : [];
                if (! is_int($options['video_duration_seconds'] ?? null)) {
                    $validator->errors()->add(
                        "configuration.pages.{$index}.options.video_duration_seconds",
                        'Controleer eerst de videoduur voordat u de pagina opslaat.',
                    );
                }
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                WallboardConfiguration::normalize(
                    (array) $this->input('configuration'),
                    $this->normalizationBase(),
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    private function normalizationBase(): array
    {
        $playlist = $this->route('wallboardPlaylist') ?? $this->route('playlist');

        return $playlist instanceof WallboardPlaylist
            ? (array) $playlist->configuration
            : [];
    }
}
