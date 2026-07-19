<?php

namespace App\Http\Requests\Admin;

use App\Models\Wallboard;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            'configuration' => ['sometimes', 'required', 'array:theme,refresh_seconds,rotation_enabled,pages,incident_override,ticker,map'],
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
            'configuration.incident_override' => ['sometimes', 'array:enabled,page_id'],
            'configuration.incident_override.enabled' => ['sometimes', 'boolean'],
            'configuration.incident_override.page_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'configuration.ticker' => ['sometimes', 'array:enabled,sources'],
            'configuration.ticker.enabled' => ['sometimes', 'boolean'],
            'configuration.ticker.sources' => ['sometimes', 'array', 'max:'.WallboardConfiguration::MAX_TICKER_SOURCES],
            'configuration.ticker.sources.*' => ['required', 'array:id,type,label,url,text'],
            'configuration.ticker.sources.*.id' => ['required', 'string', 'max:'.WallboardConfiguration::MAX_TICKER_SOURCE_ID_LENGTH, 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', 'distinct:strict'],
            'configuration.ticker.sources.*.type' => ['required', 'string', Rule::in(WallboardConfiguration::TICKER_SOURCE_TYPES)],
            'configuration.ticker.sources.*.label' => ['required', 'string', 'max:'.WallboardConfiguration::MAX_TICKER_LABEL_LENGTH],
            'configuration.ticker.sources.*.url' => ['sometimes', 'string', 'max:'.WallboardConfiguration::MAX_TICKER_URL_LENGTH],
            'configuration.ticker.sources.*.text' => ['sometimes', 'string', 'max:'.WallboardConfiguration::MAX_TICKER_INTERNAL_TEXT_LENGTH],
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
                'message' => ['body'],
                'incident_list', 'summary' => ['show_test_incidents'],
                'map' => [],
                default => array_keys($options),
            };
            if (array_diff(array_keys($options), $allowedKeys) !== []) {
                $validator->errors()->add(
                    "configuration.pages.{$index}.options",
                    'Deze opties horen niet bij het gekozen paginatype.',
                );
            }
            if ($type === 'message' && trim((string) ($options['body'] ?? '')) === '') {
                $validator->errors()->add(
                    "configuration.pages.{$index}.options.body",
                    'Een berichtpagina heeft berichttekst nodig.',
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
                if (array_diff(array_keys($source), ['id', 'type', 'label', 'url']) !== []) {
                    $validator->errors()->add(
                        "configuration.ticker.sources.{$index}",
                        'Een RSS-tickerbron mag alleen id, type, label en url bevatten.',
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
