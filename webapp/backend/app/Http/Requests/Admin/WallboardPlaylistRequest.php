<?php

namespace App\Http\Requests\Admin;

use App\Models\WallboardPlaylist;
use App\Support\WallboardConfiguration;
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
            'configuration' => [$presence, 'array:theme,refresh_seconds,rotation_enabled,pages,focus,incident_override,ticker,map'],
            'configuration.theme' => ['sometimes', 'string', Rule::in(['dark', 'light'])],
            'configuration.refresh_seconds' => ['sometimes', 'integer', 'between:5,60'],
            'configuration.rotation_enabled' => ['sometimes', 'boolean'],
            'configuration.pages' => ['sometimes', 'array', 'min:1', 'max:20'],
            'configuration.pages.*' => ['required', 'array:id,name,type,duration_seconds,options'],
            'configuration.pages.*.id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', 'distinct:strict'],
            'configuration.pages.*.name' => ['required', 'string', 'max:120'],
            'configuration.pages.*.type' => ['required', 'string', Rule::in(WallboardConfiguration::PAGE_TYPES)],
            'configuration.pages.*.duration_seconds' => ['required', 'integer', 'between:5,3600'],
            'configuration.pages.*.options' => ['sometimes', 'array:body,show_test_incidents'],
            'configuration.pages.*.options.body' => ['sometimes', 'string', 'max:2000'],
            'configuration.pages.*.options.show_test_incidents' => ['sometimes', 'boolean'],
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
