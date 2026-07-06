<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PilotIncidentReportFormService
{
    public const SETTING_KEY = 'pilot_report.form_fields';
    private const FIELD_KEY_PATTERN = '/^[a-z][a-z0-9_]{1,60}$/';
    private const FIELD_TYPES = ['section', 'text', 'textarea', 'number', 'phone', 'flight_time', 'select', 'checkbox', 'radio'];
    private const OPTION_SOURCES = ['manual', 'user_drones'];
    private const DEFAULT_PHONE_COUNTRIES = ['31', '32'];
    private const SUPPORTED_PHONE_COUNTRIES = ['31', '32'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(?User $user = null, bool $operatorOnly = false): array
    {
        $setting = SystemSetting::query()->where('key', self::SETTING_KEY)->first();
        $fields = $setting === null || ! is_array($setting->value)
            ? $this->defaultFields()
            : $setting->value;

        $fields = collect($fields)
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(fn (array $field): array => $this->normalizeField($field))
            ->map(fn (array $field): array => $this->withResolvedOptions($field, $user))
            ->values();

        if ($operatorOnly) {
            $fields = $fields->filter(fn (array $field): bool => ($field['available_in_operator_app'] ?? true) === true);
        }

        return $fields->values()->all();
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
                throw ValidationException::withMessages(["fields.$index.key" => ['Dubbel inzetrapport veld.']]);
            }
            $seen[$key] = true;

            $validated[] = $this->normalizeField($field, $index);
        }

        if (! collect($validated)->contains(fn (array $field): bool => $field['visible'] && $field['type'] !== 'section')) {
            throw ValidationException::withMessages(['fields' => ['Minimaal een veld moet zichtbaar zijn.']]);
        }

        return $validated;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function validationRules(?User $user = null): array
    {
        $rules = [
            'custom_fields' => ['nullable', 'array'],
        ];

        foreach ($this->fields($user) as $field) {
            if (($field['visible'] ?? true) !== true) {
                continue;
            }
            if (($field['type'] ?? null) === 'section') {
                continue;
            }

            $fieldRules = [];
            $fieldRules[] = $field['required'] === true ? 'required' : 'nullable';
            $target = 'custom_fields.'.$field['key'];

            if ($field['type'] === 'number') {
                $fieldRules[] = 'integer';
                $fieldRules[] = 'min:0';
                $fieldRules[] = 'max:'.(int) ($field['max'] ?? 1440);
            } elseif ($field['type'] === 'phone') {
                $fieldRules[] = 'string';
                $fieldRules[] = 'regex:'.$this->phonePattern($field['phone_countries'] ?? self::DEFAULT_PHONE_COUNTRIES);
            } elseif ($field['type'] === 'flight_time') {
                $fieldRules[] = 'array';
                $rules[$target.'.start'] = [$field['required'] === true ? 'required' : 'nullable', 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'];
                $rules[$target.'.end'] = [$field['required'] === true ? 'required' : 'nullable', 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'];
            } elseif ($field['type'] === 'checkbox') {
                $fieldRules[] = 'boolean';
            } elseif (in_array($field['type'], ['select', 'radio'], true)) {
                $fieldRules[] = 'string';
                $fieldRules[] = Rule::in(array_column($this->resolvedOptions($field, $user), 'value'));
            } else {
                $fieldRules[] = 'string';
                $fieldRules[] = 'max:'.(int) ($field['max_length'] ?? 5000);
            }

            $rules[$target] = $fieldRules;
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
     * @return array<int, array<string, mixed>>
     */
    public function defaultFields(): array
    {
        return [
            ['key' => 'summary', 'label' => 'Samenvatting', 'type' => 'textarea', 'visible' => true, 'required' => true, 'max_length' => 5000, 'max' => 1440, 'option_source' => 'manual', 'options' => [], 'is_custom' => true],
            ['key' => 'observations', 'label' => 'Waarnemingen', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000, 'max' => 1440, 'option_source' => 'manual', 'options' => [], 'is_custom' => true],
            ['key' => 'actions_taken', 'label' => 'Uitgevoerde acties', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000, 'max' => 1440, 'option_source' => 'manual', 'options' => [], 'is_custom' => true],
            ['key' => 'result', 'label' => 'Resultaat', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000, 'max' => 1440, 'option_source' => 'manual', 'options' => [], 'is_custom' => true],
            ['key' => 'equipment_used', 'label' => 'Gebruikte middelen', 'type' => 'text', 'visible' => true, 'required' => false, 'max_length' => 5000, 'max' => 1440, 'option_source' => 'manual', 'options' => [], 'is_custom' => true],
            ['key' => 'flight_time', 'label' => 'Vluchttijd', 'type' => 'flight_time', 'visible' => true, 'required' => false, 'max_length' => 1000, 'max' => 1440, 'option_source' => 'manual', 'options' => [], 'is_custom' => true],
            ['key' => 'issues', 'label' => 'Bijzonderheden of problemen', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000, 'max' => 1440, 'option_source' => 'manual', 'options' => [], 'is_custom' => true],
        ];
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
        $optionSource = $this->cleanOptionSource($field['option_source'] ?? 'manual', $type);

        return [
            'key' => $key,
            'label' => $this->cleanLabel($field['label'] ?? ''),
            'type' => $type,
            'visible' => $visible,
            'required' => $type !== 'section' && $visible && $required,
            'max_length' => $type === 'textarea' ? 5000 : ($type === 'phone' ? 20 : 1000),
            'max' => 1440,
            'option_source' => $optionSource,
            'options' => $this->cleanOptions($field['options'] ?? [], $type, $optionSource, $index),
            'phone_countries' => $type === 'phone' ? $this->cleanPhoneCountries($field['phone_countries'] ?? self::DEFAULT_PHONE_COUNTRIES) : [],
            'width' => $this->cleanWidth($field['width'] ?? null, $type),
            'section' => $this->cleanSection($field['section'] ?? null),
            'available_in_operator_app' => filter_var($field['available_in_operator_app'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
            'is_custom' => true,
        ];
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

    private function cleanOptionSource(mixed $source, string $type): string
    {
        if (! in_array($type, ['select', 'radio'], true)) {
            return 'manual';
        }

        $value = is_string($source) ? $source : 'manual';

        return in_array($value, self::OPTION_SOURCES, true) ? $value : 'manual';
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function cleanOptions(mixed $options, string $type, string $optionSource, ?int $index): array
    {
        if (! in_array($type, ['select', 'radio'], true) || $optionSource !== 'manual') {
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

            if (mb_strlen($label) > 80 || mb_strlen($value) > 80) {
                throw ValidationException::withMessages(["fields.$index.options.$optionIndex" => ['Optie mag maximaal 80 tekens zijn.']]);
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
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function withResolvedOptions(array $field, ?User $user): array
    {
        if (! in_array($field['type'] ?? null, ['select', 'radio'], true)) {
            return $field + ['option_source' => 'manual', 'options' => []];
        }

        $field['options'] = $this->resolvedOptions($field, $user);

        return $field;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, array{label: string, value: string}>
     */
    private function resolvedOptions(array $field, ?User $user): array
    {
        if (($field['option_source'] ?? 'manual') !== 'user_drones') {
            return is_array($field['options'] ?? null) ? $field['options'] : [];
        }

        if ($user === null) {
            return [];
        }

        return Asset::query()
            ->where('type', 'drone')
            ->whereHas('assignments', fn ($assignments) => $assignments
                ->where('user_id', $user->id)
                ->whereNull('released_at'))
            ->with('droneType')
            ->orderBy('asset_tag')
            ->get()
            ->map(fn (Asset $asset): array => [
                'label' => trim(($asset->asset_tag ? $asset->asset_tag.' - ' : '').$asset->name.($asset->droneType ? ' ('.$asset->droneType->manufacturer.' '.$asset->droneType->model.')' : '')),
                'value' => (string) $asset->id,
            ])
            ->values()
            ->all();
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
