<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PilotIncidentReportFormService
{
    public const SETTING_KEY = 'pilot_report.form_fields';
    private const CUSTOM_KEY_PATTERN = '/^custom_[a-z0-9_]{2,40}$/';
    private const FIELD_TYPES = ['text', 'textarea', 'number', 'select', 'checkbox', 'radio'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        $setting = SystemSetting::query()->where('key', self::SETTING_KEY)->first();
        if ($setting === null) {
            return $this->defaultFields();
        }

        $stored = is_array($setting->value) ? $setting->value : [];
        $defaults = collect($this->defaultFields())->keyBy('key');

        return collect($stored)
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(function (array $field) use ($defaults): array {
                $key = (string) ($field['key'] ?? '');
                $default = $defaults->get($key);
                if ($default === null) {
                    return $this->normalizeCustomField($field);
                }

                return [
                    ...$default,
                    'label' => $this->cleanLabel($field['label'] ?? $default['label']),
                    'visible' => is_bool($field['visible'] ?? null) ? $field['visible'] : $default['visible'],
                    'required' => is_bool($field['required'] ?? null) ? $field['required'] : $default['required'],
                    'is_custom' => false,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    public function validateFields(array $fields): array
    {
        $defaults = collect($this->defaultFields())->keyBy('key');
        $seen = [];
        $validated = [];

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages(["fields.$index" => ['Veldconfiguratie is ongeldig.']]);
            }

            $key = (string) ($field['key'] ?? '');
            $default = $defaults->get($key);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["fields.$index.key" => ['Dubbel inzetrapport veld.']]);
            }
            $seen[$key] = true;

            if ($default === null) {
                if (! $this->isCustomKey($key)) {
                    throw ValidationException::withMessages(["fields.$index.key" => ['Extra veld sleutel moet beginnen met custom_ en alleen kleine letters, cijfers en underscores bevatten.']]);
                }

                $validated[] = $this->normalizeCustomField($field, $index);
                continue;
            }

            $visible = filter_var($field['visible'] ?? $default['visible'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $required = filter_var($field['required'] ?? $default['required'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $visible = $visible ?? $default['visible'];
            $required = $required ?? $default['required'];

            $validated[] = [
                ...$default,
                'label' => $this->cleanLabel($field['label'] ?? $default['label']),
                'visible' => $visible,
                'required' => $visible && $required,
                'is_custom' => false,
            ];
        }

        if (! collect($validated)->contains(fn (array $field): bool => $field['visible'])) {
            throw ValidationException::withMessages(['fields' => ['Minimaal één zichtbaar veld moet verplicht zijn.']]);
        }

        return $validated;
    }

    /**
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        $rules = [
            'custom_fields' => ['nullable', 'array'],
        ];

        foreach ($this->fields() as $field) {
            if (($field['visible'] ?? true) !== true) {
                continue;
            }

            $fieldRules = [];
            $fieldRules[] = $field['visible'] && $field['required'] ? 'required' : 'nullable';
            $target = ($field['is_custom'] ?? false) === true ? 'custom_fields.'.$field['key'] : $field['key'];

            if ($field['type'] === 'number') {
                $fieldRules[] = 'integer';
                $fieldRules[] = 'min:0';
                $fieldRules[] = 'max:'.(int) ($field['max'] ?? 1440);
            } elseif ($field['type'] === 'checkbox') {
                $fieldRules[] = 'boolean';
            } elseif (in_array($field['type'], ['select', 'radio'], true)) {
                $fieldRules[] = 'string';
                $fieldRules[] = Rule::in(array_column($field['options'] ?? [], 'value'));
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
            if (($field['is_custom'] ?? false) !== true || ($field['visible'] ?? true) !== true) {
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
            } elseif ($field['type'] === 'checkbox') {
                $values[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } else {
                $values[$key] = trim((string) $value);
            }
        }

        return $values;
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, visible: bool, required: bool, max_length?: int, max?: int}>
     */
    public function defaultFields(): array
    {
        return [
            ['key' => 'summary', 'label' => 'Samenvatting', 'type' => 'textarea', 'visible' => true, 'required' => true, 'max_length' => 5000, 'is_custom' => false],
            ['key' => 'observations', 'label' => 'Waarnemingen', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000, 'is_custom' => false],
            ['key' => 'actions_taken', 'label' => 'Uitgevoerde acties', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000, 'is_custom' => false],
            ['key' => 'result', 'label' => 'Resultaat', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000, 'is_custom' => false],
            ['key' => 'equipment_used', 'label' => 'Gebruikte middelen', 'type' => 'text', 'visible' => true, 'required' => false, 'max_length' => 5000, 'is_custom' => false],
            ['key' => 'flight_minutes', 'label' => 'Vluchtduur in minuten', 'type' => 'number', 'visible' => true, 'required' => false, 'max' => 1440, 'is_custom' => false],
            ['key' => 'issues', 'label' => 'Bijzonderheden of problemen', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000, 'is_custom' => false],
        ];
    }

    private function isCustomKey(string $key): bool
    {
        return preg_match(self::CUSTOM_KEY_PATTERN, $key) === 1;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function normalizeCustomField(array $field, ?int $index = null): array
    {
        $key = (string) ($field['key'] ?? '');
        if (! $this->isCustomKey($key)) {
            throw ValidationException::withMessages([$index === null ? 'fields.key' : "fields.$index.key" => ['Extra veld sleutel is ongeldig.']]);
        }

        $type = (string) ($field['type'] ?? 'text');
        if (! in_array($type, self::FIELD_TYPES, true)) {
            throw ValidationException::withMessages([$index === null ? 'fields.type' : "fields.$index.type" => ['Veldtype is ongeldig.']]);
        }

        $visible = filter_var($field['visible'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $required = filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        $options = $this->cleanOptions($field['options'] ?? [], $type, $index);

        return [
            'key' => $key,
            'label' => $this->cleanLabel($field['label'] ?? ''),
            'type' => $type,
            'visible' => $visible,
            'required' => $visible && $required,
            'max_length' => $type === 'textarea' ? 5000 : 1000,
            'max' => 1440,
            'options' => $options,
            'is_custom' => true,
        ];
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
}
