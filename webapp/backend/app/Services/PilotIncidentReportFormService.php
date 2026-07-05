<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Validation\ValidationException;

final class PilotIncidentReportFormService
{
    public const SETTING_KEY = 'pilot_report.form_fields';

    /**
     * @return array<int, array{key: string, label: string, type: string, visible: bool, required: bool, max_length?: int, max?: int}>
     */
    public function fields(): array
    {
        $stored = SystemSetting::value(self::SETTING_KEY, []);
        $storedByKey = collect(is_array($stored) ? $stored : [])->keyBy(fn ($field): string => (string) ($field['key'] ?? ''));

        return collect($this->defaultFields())
            ->map(function (array $default) use ($storedByKey): array {
                $storedField = $storedByKey->get($default['key']);
                if (! is_array($storedField)) {
                    return $default;
                }

                return [
                    ...$default,
                    'label' => $this->cleanLabel($storedField['label'] ?? $default['label']),
                    'visible' => is_bool($storedField['visible'] ?? null) ? $storedField['visible'] : $default['visible'],
                    'required' => is_bool($storedField['required'] ?? null) ? $storedField['required'] : $default['required'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<int, array{key: string, label: string, type: string, visible: bool, required: bool, max_length?: int, max?: int}>
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
            if ($default === null) {
                throw ValidationException::withMessages(["fields.$index.key" => ['Onbekend inzetrapport veld.']]);
            }

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["fields.$index.key" => ['Dubbel inzetrapport veld.']]);
            }
            $seen[$key] = true;

            $visible = filter_var($field['visible'] ?? $default['visible'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $required = filter_var($field['required'] ?? $default['required'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $visible = $visible ?? $default['visible'];
            $required = $required ?? $default['required'];

            $validated[] = [
                ...$default,
                'label' => $this->cleanLabel($field['label'] ?? $default['label']),
                'visible' => $visible,
                'required' => $visible && $required,
            ];
        }

        foreach ($defaults as $key => $default) {
            if (! isset($seen[$key])) {
                $validated[] = $default;
            }
        }

        if (! collect($validated)->contains(fn (array $field): bool => $field['visible'] && $field['required'])) {
            throw ValidationException::withMessages(['fields' => ['Minimaal één zichtbaar veld moet verplicht zijn.']]);
        }

        return $validated;
    }

    /**
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->fields() as $field) {
            $fieldRules = [];
            $fieldRules[] = $field['visible'] && $field['required'] ? 'required' : 'nullable';
            $fieldRules[] = $field['type'] === 'number' ? 'integer' : 'string';

            if ($field['type'] === 'number') {
                $fieldRules[] = 'min:0';
                $fieldRules[] = 'max:'.(int) ($field['max'] ?? 1440);
            } else {
                $fieldRules[] = 'max:'.(int) ($field['max_length'] ?? 5000);
            }

            $rules[$field['key']] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, visible: bool, required: bool, max_length?: int, max?: int}>
     */
    public function defaultFields(): array
    {
        return [
            ['key' => 'summary', 'label' => 'Samenvatting', 'type' => 'textarea', 'visible' => true, 'required' => true, 'max_length' => 5000],
            ['key' => 'observations', 'label' => 'Waarnemingen', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000],
            ['key' => 'actions_taken', 'label' => 'Uitgevoerde acties', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000],
            ['key' => 'result', 'label' => 'Resultaat', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000],
            ['key' => 'equipment_used', 'label' => 'Gebruikte middelen', 'type' => 'text', 'visible' => true, 'required' => false, 'max_length' => 5000],
            ['key' => 'flight_minutes', 'label' => 'Vluchtduur in minuten', 'type' => 'number', 'visible' => true, 'required' => false, 'max' => 1440],
            ['key' => 'issues', 'label' => 'Bijzonderheden of problemen', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000],
        ];
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
