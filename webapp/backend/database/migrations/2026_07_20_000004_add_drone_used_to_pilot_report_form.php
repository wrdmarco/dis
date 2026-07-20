<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTING_KEY = 'pilot_report.form_fields';

    private const MIGRATION_MARKER_KEY = 'migration.2026_07_20_000004.drone_used_added';

    /** @var array<string, mixed> */
    private const FIELD = [
        'key' => 'drone_used',
        'label' => 'Gebruikte drone',
        'type' => 'select',
        'visible' => true,
        'required' => false,
        'max_length' => 1000,
        'max' => 1440,
        'option_source' => 'user_drones',
        'options' => [],
        'is_custom' => true,
    ];

    public function up(): void
    {
        $fields = $this->storedFields();
        if ($fields === null || $this->containsDroneUsed($fields)) {
            return;
        }

        $fields[] = self::FIELD;
        $this->storeFields($fields);
        DB::table('system_settings')->updateOrInsert(
            ['key' => self::MIGRATION_MARKER_KEY],
            [
                'value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_sensitive' => false,
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $marker = DB::table('system_settings')->where('key', self::MIGRATION_MARKER_KEY)->value('value');
        if ($marker !== true && $marker !== 'true') {
            return;
        }

        $fields = $this->storedFields();
        if ($fields === null) {
            DB::table('system_settings')->where('key', self::MIGRATION_MARKER_KEY)->delete();

            return;
        }

        $filtered = array_values(array_filter(
            $fields,
            fn (mixed $field): bool => ! is_array($field)
                || ($field['key'] ?? null) !== 'drone_used'
                || $this->canonicalize($field) !== $this->canonicalize(self::FIELD),
        ));

        if (count($filtered) !== count($fields)) {
            $this->storeFields($filtered);
        }
        DB::table('system_settings')->where('key', self::MIGRATION_MARKER_KEY)->delete();
    }

    /**
     * @return list<mixed>|null
     */
    private function storedFields(): ?array
    {
        $value = DB::table('system_settings')->where('key', self::SETTING_KEY)->value('value');
        if (! is_string($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
    }

    /** @param list<mixed> $fields */
    private function containsDroneUsed(array $fields): bool
    {
        foreach ($fields as $field) {
            if (is_array($field) && ($field['key'] ?? null) === 'drone_used') {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $fields */
    private function storeFields(array $fields): void
    {
        DB::table('system_settings')
            ->where('key', self::SETTING_KEY)
            ->update([
                'value' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
};
