<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class IncidentFormService
{
    public const SETTING_KEY = 'incident.form_fields';
    private const FIELD_KEY_PATTERN = '/^[a-z][a-z0-9_]{1,60}$/';
    private const FIELD_TYPES = ['section', 'text', 'textarea', 'number', 'flight_time', 'select', 'checkbox', 'radio'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        $stored = SystemSetting::value(self::SETTING_KEY, []);

        return collect(is_array($stored) ? $stored : [])
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(fn (array $field): array => $this->normalizeField($field))
            ->values()
            ->all();
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

        return $validated;
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
            'max_length' => $type === 'textarea' ? 5000 : 1000,
            'max' => 999999,
            'options' => $this->cleanOptions($field['options'] ?? [], $type, $index),
            'width' => $this->cleanWidth($field['width'] ?? null, $type),
            'section' => $this->cleanSection($field['section'] ?? null),
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
