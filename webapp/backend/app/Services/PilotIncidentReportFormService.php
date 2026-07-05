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
    private const FIELD_TYPES = ['text', 'textarea', 'number', 'select', 'checkbox', 'radio'];
    private const OPTION_SOURCES = ['manual', 'user_drones'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(?User $user = null): array
    {
        $setting = SystemSetting::query()->where('key', self::SETTING_KEY)->first();
        $fields = $setting === null || ! is_array($setting->value)
            ? $this->defaultFields()
            : $setting->value;

        return collect($fields)
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(fn (array $field): array => $this->normalizeField($field))
            ->map(fn (array $field): array => $this->withResolvedOptions($field, $user))
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
                throw ValidationException::withMessages(["fields.$index.key" => ['Dubbel inzetrapport veld.']]);
            }
            $seen[$key] = true;

            $validated[] = $this->normalizeField($field, $index);
        }

        if (! collect($validated)->contains(fn (array $field): bool => $field['visible'])) {
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

            $fieldRules = [];
            $fieldRules[] = $field['required'] === true ? 'required' : 'nullable';
            $target = 'custom_fields.'.$field['key'];

            if ($field['type'] === 'number') {
                $fieldRules[] = 'integer';
                $fieldRules[] = 'min:0';
                $fieldRules[] = 'max:'.(int) ($field['max'] ?? 1440);
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
            ['key' => 'flight_minutes', 'label' => 'Vluchtduur in minuten', 'type' => 'number', 'visible' => true, 'required' => false, 'max_length' => 1000, 'max' => 1440, 'option_source' => 'manual', 'options' => [], 'is_custom' => true],
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
            'required' => $visible && $required,
            'max_length' => $type === 'textarea' ? 5000 : 1000,
            'max' => 1440,
            'option_source' => $optionSource,
            'options' => $this->cleanOptions($field['options'] ?? [], $type, $optionSource, $index),
            'is_custom' => true,
        ];
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
}
