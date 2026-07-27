<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLE = 'deployment_request_workflow_revisions';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::transaction(function (): void {
            $published = DB::table(self::TABLE)
                ->where('status', 'published')
                ->whereNotNull('version')
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($published !== null) {
                $configuration = $this->decodeConfiguration($published->configuration);
                $transformed = $configuration === null
                    ? null
                    : $this->splitLocations($configuration);

                if ($transformed !== null) {
                    $now = now();
                    $nextVersion = ((int) (DB::table(self::TABLE)->max('version') ?? 0)) + 1;

                    DB::table(self::TABLE)->insert([
                        'id' => (string) Str::ulid(),
                        'version' => $nextVersion,
                        'status' => 'published',
                        'draft_marker' => null,
                        'lock_version' => 1,
                        'configuration' => $this->encodeConfiguration($transformed),
                        // This revision is published by the release migration,
                        // not by the actor who published the source revision.
                        'created_by' => null,
                        'updated_by' => null,
                        'published_by' => null,
                        'published_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $draft = DB::table(self::TABLE)
                ->where('status', 'draft')
                ->where('draft_marker', 'active')
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                return;
            }

            $configuration = $this->decodeConfiguration($draft->configuration);
            $transformed = $configuration === null
                ? null
                : $this->splitLocations($configuration);

            if ($transformed === null) {
                return;
            }

            DB::table(self::TABLE)
                ->where('id', $draft->id)
                ->update([
                    'configuration' => $this->encodeConfiguration($transformed),
                    'lock_version' => ((int) $draft->lock_version) + 1,
                    'updated_by' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    public function down(): void
    {
        // Published workflow revisions are immutable operational history. A
        // rollback must not delete or rewrite revisions and requests created
        // after this migration.
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>|null
     */
    private function splitLocations(array $configuration): ?array
    {
        $fields = $configuration['fields'] ?? null;
        $bindings = $configuration['bindings'] ?? null;
        if (! is_array($fields) || ! is_array($bindings)) {
            return null;
        }

        if ($this->locationsAreCompliant($fields, $bindings)) {
            return null;
        }

        $lastSeenAt = [
            'key' => 'last_seen_at',
            'label' => 'Laatst gezien op',
            'type' => 'datetime',
            'scope' => 'common',
            'required' => true,
            'operator_visible' => true,
            'help_text' => null,
            'options' => [],
        ];
        $lastSeenLocation = [
            'key' => 'last_seen_location',
            'label' => 'Laatst gezien locatie',
            'type' => 'address',
            'scope' => 'common',
            'required' => true,
            'operator_visible' => true,
            'help_text' => 'De locatie waar de persoon, het dier of het object voor het laatst is gezien.',
            'options' => [],
        ];
        $section = [
            'key' => 'deployment_location_section',
            'label' => 'Opkomstlocatie',
            'type' => 'section',
            'scope' => 'common',
            'required' => false,
            'operator_visible' => false,
            'help_text' => null,
            'options' => [],
        ];
        $deploymentLocation = [
            'key' => 'deployment_location',
            'label' => 'Opkomstlocatie',
            'type' => 'address',
            'scope' => 'common',
            'required' => true,
            'operator_visible' => true,
            'help_text' => 'De verzamel- of opkomstlocatie voor de inzet; dit staat los van de laatst gezien locatie.',
            'options' => [],
        ];

        $commonSectionIndex = $this->fieldIndex($configuration['fields'], 'common_section');
        $lastSeenAtIndex = $this->fieldIndex($configuration['fields'], 'last_seen_at');
        if ($lastSeenAtIndex === null) {
            $lastSeenAtIndex = $commonSectionIndex === null ? 0 : $commonSectionIndex + 1;
            array_splice($configuration['fields'], $lastSeenAtIndex, 0, [$lastSeenAt]);
        } else {
            $configuration['fields'][$lastSeenAtIndex] = [
                ...$configuration['fields'][$lastSeenAtIndex],
                ...$lastSeenAt,
            ];
        }

        $lastSeenLocationIndex = $this->fieldIndex($configuration['fields'], 'last_seen_location');
        if ($lastSeenLocationIndex === null) {
            array_splice($configuration['fields'], $lastSeenAtIndex + 1, 0, [$lastSeenLocation]);
        } else {
            $configuration['fields'][$lastSeenLocationIndex] = [
                ...$configuration['fields'][$lastSeenLocationIndex],
                ...$lastSeenLocation,
            ];
        }

        $sectionIndex = $this->fieldIndex($configuration['fields'], 'deployment_location_section');
        if ($sectionIndex === null) {
            $deploymentLocationIndex = $this->fieldIndex($configuration['fields'], 'deployment_location');
            if ($deploymentLocationIndex === null) {
                $insertAt = ($this->fieldIndex($configuration['fields'], 'last_seen_direction')
                    ?? $this->fieldIndex($configuration['fields'], 'last_seen_location')
                    ?? $lastSeenAtIndex) + 1;
                array_splice($configuration['fields'], $insertAt, 0, [$section, $deploymentLocation]);
            } else {
                array_splice($configuration['fields'], $deploymentLocationIndex, 0, [$section]);
            }
        } else {
            $configuration['fields'][$sectionIndex] = [
                ...$configuration['fields'][$sectionIndex],
                ...$section,
            ];
            $deploymentLocationIndex = $this->fieldIndex($configuration['fields'], 'deployment_location');
            if ($deploymentLocationIndex === null) {
                array_splice($configuration['fields'], $sectionIndex + 1, 0, [$deploymentLocation]);
            } else {
                $configuration['fields'][$deploymentLocationIndex] = [
                    ...$configuration['fields'][$deploymentLocationIndex],
                    ...$deploymentLocation,
                ];
            }
        }

        // A custom draft can contain the deployment field without its section.
        // The insertion above already creates both when neither exists; this
        // final normalization handles that partial shape without duplication.
        $deploymentLocationIndex = $this->fieldIndex($configuration['fields'], 'deployment_location');
        if ($deploymentLocationIndex !== null) {
            $configuration['fields'][$deploymentLocationIndex] = [
                ...$configuration['fields'][$deploymentLocationIndex],
                ...$deploymentLocation,
            ];
        }

        $normalizedBindings = [];
        foreach ($configuration['bindings'] as $binding) {
            if (! is_array($binding)) {
                $normalizedBindings[] = $binding;

                continue;
            }
            $fieldKey = $binding['field_key'] ?? null;
            $target = $binding['target'] ?? null;
            if (in_array($fieldKey, ['last_seen_location', 'deployment_location'], true)
                || $target === 'location_label') {
                continue;
            }
            $normalizedBindings[] = $binding;
        }
        $normalizedBindings[] = [
            'field_key' => 'deployment_location',
            'target' => 'location_label',
        ];
        $configuration['bindings'] = $normalizedBindings;

        return $configuration;
    }

    /** @param list<mixed> $fields */
    private function fieldIndex(array $fields, string $key): ?int
    {
        foreach ($fields as $index => $field) {
            if (is_array($field) && ($field['key'] ?? null) === $key) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<mixed> $fields @param list<mixed> $bindings */
    private function locationsAreCompliant(array $fields, array $bindings): bool
    {
        foreach ([
            'last_seen_at' => 'datetime',
            'last_seen_location' => 'address',
            'deployment_location' => 'address',
        ] as $key => $type) {
            $index = $this->fieldIndex($fields, $key);
            $field = $index === null ? null : $fields[$index];
            if (! is_array($field)
                || ($field['type'] ?? null) !== $type
                || ($field['scope'] ?? null) !== 'common'
                || ($field['required'] ?? false) !== true
                || ($field['operator_visible'] ?? false) !== true) {
                return false;
            }
        }

        $locationBindingCount = 0;
        foreach ($bindings as $binding) {
            if (! is_array($binding)) {
                continue;
            }
            if (($binding['field_key'] ?? null) === 'last_seen_location') {
                return false;
            }
            if (($binding['target'] ?? null) === 'location_label') {
                $locationBindingCount++;
                if (($binding['field_key'] ?? null) !== 'deployment_location') {
                    return false;
                }
            }
        }

        return $locationBindingCount === 1;
    }

    /** @return array<string, mixed>|null */
    private function decodeConfiguration(mixed $configuration): ?array
    {
        if (is_array($configuration)) {
            return $configuration;
        }
        if (! is_string($configuration)) {
            return null;
        }

        $decoded = json_decode($configuration, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function encodeConfiguration(array $configuration): string
    {
        return json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
};
