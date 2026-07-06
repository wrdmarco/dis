<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class IncidentFormService
{
    public const SETTING_KEY = 'incident.form_fields';
    public const LAYOUT_SETTING_KEY = 'incident.form_layout';
    private const FIELD_KEY_PATTERN = '/^[a-z][a-z0-9_]{1,60}$/';
    private const FIELD_TYPES = ['section', 'text', 'textarea', 'number', 'phone', 'flight_time', 'select', 'checkbox', 'radio'];
    private const DEFAULT_PHONE_COUNTRIES = ['31', '32'];
    private const SUPPORTED_PHONE_COUNTRIES = ['31', '32'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(bool $operatorOnly = false): array
    {
        $stored = SystemSetting::value(self::SETTING_KEY, null);
        $fields = is_array($stored) && $stored !== [] ? $stored : $this->defaultFields();

        $fields = $this->withRequiredDefaultFields(collect($fields)
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(fn (array $field): array => $this->normalizeField($field))
            ->values())
            ->values();

        if ($operatorOnly) {
            $fields = $fields->filter(fn (array $field): bool => ($field['available_in_operator_app'] ?? true) === true);
        }

        return $fields->values()->all();
    }

    /**
     * @return array<int, array{key: string, label: string, visible: bool, width: string, locked?: bool}>
     */
    public function layout(): array
    {
        $stored = SystemSetting::value(self::LAYOUT_SETTING_KEY, null);
        $items = is_array($stored) ? $stored : $this->defaultLayout();

        return $this->normalizeLayout($items);
    }

    /**
     * @param array<int, mixed> $layout
     * @return array<int, array{key: string, label: string, visible: bool, width: string}>
     */
    public function validateLayout(array $layout): array
    {
        return $this->normalizeLayout($layout);
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    public function validateFields(array $fields): array
    {
        $seen = [];
        $validated = [];

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages(["fields.$index" => ['Veldconfiguratie is ongeldig.']]);
            }

            $key = (string) ($field['key'] ?? '');
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["fields.$index.key" => ['Dubbel incidentveld.']]);
            }
            $seen[$key] = true;
            $validated[] = $this->normalizeField($field, $index);
        }

        return $this->withRequiredDefaultFields(collect($validated))->values()->all();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function validationRules(bool $partial = false): array
    {
        $rules = [
            'custom_fields' => [$partial ? 'sometimes' : 'nullable', 'array'],
        ];

        foreach ($this->fields() as $field) {
            if (($field['visible'] ?? true) !== true) {
                continue;
            }
            if (($field['type'] ?? null) === 'section') {
                continue;
            }

            $fieldRules = [];
            if ($partial) {
                $fieldRules[] = 'sometimes';
            } else {
                $fieldRules[] = $field['required'] === true ? 'required' : 'nullable';
            }
            if ($field['type'] === 'number') {
                $fieldRules[] = 'integer';
                $fieldRules[] = 'min:0';
                $fieldRules[] = 'max:999999';
            } elseif ($field['type'] === 'phone') {
                $fieldRules[] = 'string';
                $fieldRules[] = 'regex:'.$this->phonePattern($field['phone_countries'] ?? self::DEFAULT_PHONE_COUNTRIES);
            } elseif ($field['type'] === 'flight_time') {
                $fieldRules[] = 'array';
                $rules['custom_fields.'.$field['key'].'.start'] = [$partial ? 'sometimes' : ($field['required'] === true ? 'required' : 'nullable'), 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'];
                $rules['custom_fields.'.$field['key'].'.end'] = [$partial ? 'sometimes' : ($field['required'] === true ? 'required' : 'nullable'), 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'];
            } elseif ($field['type'] === 'checkbox') {
                $fieldRules[] = 'boolean';
            } elseif (in_array($field['type'], ['select', 'radio'], true)) {
                $fieldRules[] = 'string';
                $fieldRules[] = Rule::in(array_column($field['options'] ?? [], 'value'));
            } else {
                $fieldRules[] = 'string';
                $fieldRules[] = 'max:'.(int) ($field['max_length'] ?? 5000);
            }

            $rules['custom_fields.'.$field['key']] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeCustomValues(array $data): array
    {
        $incoming = $data['custom_fields'] ?? [];
        if (! is_array($incoming)) {
            return [];
        }

        $values = [];
        foreach ($this->fields() as $field) {
            if (($field['visible'] ?? true) !== true) {
                continue;
            }
            if (($field['type'] ?? null) === 'section') {
                continue;
            }

            $key = (string) $field['key'];
            if (! array_key_exists($key, $incoming)) {
                continue;
            }

            $value = $incoming[$key];
            if ($value === null || $value === '') {
                $values[$key] = null;
            } elseif ($field['type'] === 'number') {
                $values[$key] = (int) $value;
            } elseif ($field['type'] === 'phone') {
                $values[$key] = $this->normalizePhoneValue($value);
            } elseif ($field['type'] === 'flight_time') {
                $values[$key] = $this->normalizeFlightTimeValue($value);
            } elseif ($field['type'] === 'checkbox') {
                $values[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } else {
                $values[$key] = trim((string) $value);
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function normalizeField(array $field, ?int $index = null): array
    {
        $key = (string) ($field['key'] ?? '');
        if (preg_match(self::FIELD_KEY_PATTERN, $key) !== 1) {
            throw ValidationException::withMessages([$index === null ? 'fields.key' : "fields.$index.key" => ['Veldsleutel moet beginnen met een kleine letter en mag alleen kleine letters, cijfers en underscores bevatten.']]);
        }

        $type = (string) ($field['type'] ?? 'text');
        if (! in_array($type, self::FIELD_TYPES, true)) {
            throw ValidationException::withMessages([$index === null ? 'fields.type' : "fields.$index.type" => ['Veldtype is ongeldig.']]);
        }

        $visible = filter_var($field['visible'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $required = filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        return [
            'key' => $key,
            'label' => $this->cleanLabel($field['label'] ?? ''),
            'type' => $type,
            'visible' => $visible,
            'required' => $type !== 'section' && $visible && $required,
            'max_length' => $type === 'textarea' ? 5000 : ($type === 'phone' ? 20 : 1000),
            'max' => 999999,
            'options' => $this->cleanOptions($field['options'] ?? [], $type, $index),
            'phone_countries' => $type === 'phone' ? $this->cleanPhoneCountries($field['phone_countries'] ?? self::DEFAULT_PHONE_COUNTRIES) : [],
            'width' => $this->cleanWidth($field['width'] ?? null, $type),
            'section' => $this->cleanSection($field['section'] ?? null),
            'locked' => $this->isRequiredDefaultField($key),
            'expose_to_push' => filter_var($field['expose_to_push'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
            'available_in_operator_app' => filter_var($field['available_in_operator_app'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
            'is_custom' => true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultFields(): array
    {
        return [
            ['key' => 'reporter_name', 'label' => 'Naam melder', 'type' => 'text', 'visible' => true, 'required' => true, 'width' => 'half', 'expose_to_push' => true, 'available_in_operator_app' => true],
            ['key' => 'reporter_phone', 'label' => 'Telefoonnummer melder', 'type' => 'phone', 'visible' => true, 'required' => true, 'width' => 'half', 'phone_countries' => self::DEFAULT_PHONE_COUNTRIES, 'expose_to_push' => false, 'available_in_operator_app' => true],
            ['key' => 'requesting_organization', 'label' => 'Aanvragende organisatie', 'type' => 'text', 'visible' => true, 'required' => true, 'width' => 'full', 'expose_to_push' => true, 'available_in_operator_app' => true],
            ['key' => 'requesting_unit', 'label' => 'Dienst / eenheid', 'type' => 'text', 'visible' => true, 'required' => false, 'width' => 'half', 'expose_to_push' => true, 'available_in_operator_app' => true],
            ['key' => 'on_scene_contact_name', 'label' => 'Contact ter plaatse', 'type' => 'text', 'visible' => true, 'required' => false, 'width' => 'half', 'expose_to_push' => false, 'available_in_operator_app' => false],
            ['key' => 'on_scene_contact_phone', 'label' => 'Telefoon ter plaatse', 'type' => 'phone', 'visible' => true, 'required' => false, 'width' => 'half', 'phone_countries' => self::DEFAULT_PHONE_COUNTRIES, 'expose_to_push' => false, 'available_in_operator_app' => false],
            ['key' => 'on_scene_contact_role', 'label' => 'Functie / rol contactpersoon', 'type' => 'text', 'visible' => true, 'required' => false, 'width' => 'half', 'expose_to_push' => false, 'available_in_operator_app' => false],
            ['key' => 'required_resources', 'label' => 'Benodigde middelen', 'type' => 'textarea', 'visible' => true, 'required' => false, 'width' => 'full', 'expose_to_push' => true, 'available_in_operator_app' => true],
        ];
    }

    private function withRequiredDefaultFields($fields)
    {
        $byKey = $fields->keyBy('key');
        foreach ($this->defaultFields() as $field) {
            if (! $this->isRequiredDefaultField((string) $field['key'])) {
                continue;
            }

            if (! $byKey->has($field['key'])) {
                $byKey->put($field['key'], $this->normalizeField($field));
                continue;
            }

            $current = $byKey->get($field['key']);
            $current['visible'] = true;
            $current['required'] = true;
            $current['locked'] = true;
            $current['available_in_operator_app'] = true;
            $byKey->put($field['key'], $current);
        }

        return $byKey->values();
    }

    private function isRequiredDefaultField(string $key): bool
    {
        return in_array($key, ['reporter_name', 'reporter_phone', 'requesting_organization'], true);
    }

    /**
     * @return array<int, array{key: string, label: string, visible: bool, width: string, locked?: bool}>
     */
    private function defaultLayout(): array
    {
        return [
            ...$this->fixedDefaultLayout(),
            ...$this->customFieldLayoutItems(),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, visible: bool, width: string, locked?: bool}>
     */
    private function fixedDefaultLayout(): array
    {
        return [
            ['key' => 'section_incident', 'label' => 'Sectie: incident', 'visible' => true, 'width' => 'full', 'locked' => true],
            ['key' => 'title', 'label' => 'Titel', 'visible' => true, 'width' => 'full', 'locked' => true],
            ['key' => 'description', 'label' => 'Details', 'visible' => true, 'width' => 'full', 'locked' => true],
            ['key' => 'section_dispatch', 'label' => 'Sectie: inzet', 'visible' => true, 'width' => 'full'],
            ['key' => 'priority', 'label' => 'Prioriteit', 'visible' => true, 'width' => 'half'],
            ['key' => 'status', 'label' => 'Status', 'visible' => true, 'width' => 'half'],
            ['key' => 'teams', 'label' => 'Teams', 'visible' => true, 'width' => 'full'],
            ['key' => 'coordinator', 'label' => 'Coordinator', 'visible' => true, 'width' => 'full'],
            ['key' => 'section_location', 'label' => 'Sectie: locatie', 'visible' => true, 'width' => 'full', 'locked' => true],
            ['key' => 'location_search', 'label' => 'Adres zoeken', 'visible' => true, 'width' => 'half', 'locked' => true],
            ['key' => 'location_map', 'label' => 'Kaart opkomstlocatie', 'visible' => true, 'width' => 'half', 'locked' => true],
            ['key' => 'section_drone', 'label' => 'Sectie: drone vluchtcheck', 'visible' => true, 'width' => 'full'],
            ['key' => 'drone_status', 'label' => 'Drone vluchtcheck status', 'visible' => true, 'width' => 'full'],
            ['key' => 'drone_weather', 'label' => 'Weer', 'visible' => true, 'width' => 'half'],
            ['key' => 'drone_airspace', 'label' => 'Luchtruim', 'visible' => true, 'width' => 'half'],
            ['key' => 'drone_aeret_link', 'label' => 'Aeret link', 'visible' => true, 'width' => 'full'],
            ['key' => 'drone_aeret_map', 'label' => 'Aeret kaart', 'visible' => true, 'width' => 'full'],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, visible: bool, width: string, locked?: bool}>
     */
    private function customFieldLayoutItems(): array
    {
        return collect($this->fields())
            ->map(fn (array $field): array => [
                'key' => 'custom_field:'.$field['key'],
                'label' => (string) $field['label'],
                'visible' => (bool) ($field['visible'] ?? true),
                'width' => in_array(($field['width'] ?? 'half'), ['half', 'full'], true) ? (string) $field['width'] : 'half',
                'locked' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<int, mixed> $layout
     * @return array<int, array{key: string, label: string, visible: bool, width: string, locked?: bool}>
     */
    private function normalizeLayout(array $layout): array
    {
        $defaults = collect($this->defaultLayout())->keyBy('key');
        $customFieldKeys = collect($this->customFieldLayoutItems())->pluck('key')->all();
        $seen = [];
        $normalized = [];

        foreach ($layout as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = (string) ($item['key'] ?? '');
            if ($key === 'incident_details') {
                foreach (['section_incident', 'title', 'description'] as $replacementKey) {
                    if (! isset($seen[$replacementKey])) {
                        $replacement = $defaults->get($replacementKey);
                        $seen[$replacementKey] = true;
                        $normalized[] = $replacement;
                    }
                }
                continue;
            }

            if ($key === 'reporter_request') {
                foreach ($customFieldKeys as $replacementKey) {
                    if (! isset($seen[$replacementKey])) {
                        $replacement = $defaults->get($replacementKey);
                        $seen[$replacementKey] = true;
                        $normalized[] = $replacement;
                    }
                }
                continue;
            }

            if ($key === 'priority_teams') {
                foreach (['section_dispatch', 'priority', 'status', 'teams'] as $replacementKey) {
                    if (! isset($seen[$replacementKey])) {
                        $replacement = $defaults->get($replacementKey);
                        $seen[$replacementKey] = true;
                        $normalized[] = $replacement;
                    }
                }
                continue;
            }

            if ($key === 'location') {
                foreach (['section_location', 'location_search', 'location_map'] as $replacementKey) {
                    if (! isset($seen[$replacementKey])) {
                        $replacement = $defaults->get($replacementKey);
                        $seen[$replacementKey] = true;
                        $normalized[] = $replacement;
                    }
                }
                continue;
            }

            if ($key === 'resources') {
                continue;
            }

            if ($key === 'custom_fields') {
                foreach ($customFieldKeys as $replacementKey) {
                    if (! isset($seen[$replacementKey])) {
                        $replacement = $defaults->get($replacementKey);
                        $seen[$replacementKey] = true;
                        $normalized[] = $replacement;
                    }
                }
                continue;
            }

            if ($key === 'drone_context') {
                foreach (['section_drone', 'drone_status', 'drone_weather', 'drone_airspace', 'drone_aeret_link', 'drone_aeret_map'] as $replacementKey) {
                    if (! isset($seen[$replacementKey])) {
                        $replacement = $defaults->get($replacementKey);
                        $seen[$replacementKey] = true;
                        $normalized[] = $replacement;
                    }
                }
                continue;
            }

            if (! $defaults->has($key) || isset($seen[$key])) {
                continue;
            }

            $default = $defaults->get($key);
            $seen[$key] = true;
            $normalized[] = [
                'key' => $key,
                'label' => (string) ($default['label'] ?? $key),
                'visible' => ($default['locked'] ?? false) === true ? true : (filter_var($item['visible'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true),
                'width' => in_array(($item['width'] ?? 'full'), ['half', 'full'], true) ? (string) $item['width'] : 'full',
                'locked' => (bool) ($default['locked'] ?? false),
            ];
        }

        foreach ($defaults as $key => $default) {
            if (! isset($seen[$key])) {
                $normalized[] = $default;
            }
        }

        return $normalized;
    }

    private function cleanWidth(mixed $width, string $type): string
    {
        if ($type === 'section') {
            return 'full';
        }

        $value = is_string($width) ? $width : 'half';

        return in_array($value, ['half', 'full'], true) ? $value : 'half';
    }

    private function cleanSection(mixed $section): ?string
    {
        $value = trim(is_string($section) ? $section : '');

        return $value === '' ? null : mb_substr($value, 0, 80);
    }

    private function cleanLabel(mixed $label): string
    {
        $value = trim(is_string($label) ? $label : '');
        if ($value === '') {
            throw ValidationException::withMessages(['fields.label' => ['Label is verplicht.']]);
        }

        if (mb_strlen($value) > 80) {
            throw ValidationException::withMessages(['fields.label' => ['Label mag maximaal 80 tekens zijn.']]);
        }

        return $value;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function cleanOptions(mixed $options, string $type, ?int $index): array
    {
        if (! in_array($type, ['select', 'radio'], true)) {
            return [];
        }

        if (! is_array($options)) {
            throw ValidationException::withMessages([$index === null ? 'fields.options' : "fields.$index.options" => ['Opties zijn verplicht voor dit veldtype.']]);
        }

        $cleaned = [];
        $seen = [];
        foreach ($options as $optionIndex => $option) {
            if (! is_array($option)) {
                continue;
            }

            $label = trim((string) ($option['label'] ?? ''));
            $value = trim((string) ($option['value'] ?? $label));
            if ($label === '' || $value === '') {
                continue;
            }

            if (isset($seen[$value])) {
                throw ValidationException::withMessages(["fields.$index.options.$optionIndex" => ['Dubbele optiewaarde.']]);
            }

            $seen[$value] = true;
            $cleaned[] = ['label' => $label, 'value' => $value];
        }

        if (count($cleaned) < 2) {
            throw ValidationException::withMessages([$index === null ? 'fields.options' : "fields.$index.options" => ['Dropdown en radio velden hebben minimaal twee opties nodig.']]);
        }

        return $cleaned;
    }

    /**
     * @return list<string>
     */
    private function cleanPhoneCountries(mixed $countries): array
    {
        $values = is_array($countries) ? $countries : self::DEFAULT_PHONE_COUNTRIES;
        $cleaned = collect($values)
            ->filter(fn (mixed $country): bool => is_string($country) || is_numeric($country))
            ->map(fn (mixed $country): string => preg_replace('/\D/', '', (string) $country) ?? '')
            ->filter(fn (string $country): bool => in_array($country, self::SUPPORTED_PHONE_COUNTRIES, true))
            ->unique()
            ->values()
            ->all();

        return $cleaned === [] ? self::DEFAULT_PHONE_COUNTRIES : $cleaned;
    }

    private function phonePattern(mixed $countries): string
    {
        $countryPattern = implode('|', array_map('preg_quote', $this->cleanPhoneCountries($countries)));

        return '/^\+('.$countryPattern.')[\s-]?[1-9](?:[\s-]?[0-9]){7,11}$/';
    }

    /**
     * @return array{start: string|null, end: string|null, duration_minutes: int|null}
     */
    private function normalizeFlightTimeValue(mixed $value): array
    {
        $start = is_array($value) && is_string($value['start'] ?? null) ? $value['start'] : null;
        $end = is_array($value) && is_string($value['end'] ?? null) ? $value['end'] : null;

        return [
            'start' => $this->cleanTimeValue($start),
            'end' => $this->cleanTimeValue($end),
            'duration_minutes' => $this->flightDurationMinutes($start, $end),
        ];
    }

    private function normalizePhoneValue(mixed $value): string
    {
        return preg_replace('/[^\d+]/', '', trim((string) $value)) ?? '';
    }

    private function cleanTimeValue(?string $value): ?string
    {
        $time = trim((string) $value);
        return preg_match('/^([01]\d|2[0-4]):[0-5]\d$/', $time) === 1 ? $time : null;
    }

    private function flightDurationMinutes(?string $start, ?string $end): ?int
    {
        $start = $this->cleanTimeValue($start);
        $end = $this->cleanTimeValue($end);
        if ($start === null || $end === null) {
            return null;
        }

        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $startTotal = $startHour * 60 + $startMinute;
        $endTotal = $endHour * 60 + $endMinute;
        if ($endTotal < $startTotal) {
            $endTotal += 24 * 60;
        }

        return $endTotal - $startTotal;
    }
}
