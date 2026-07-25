<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PROVENANCE_TABLE = 'vacation_availability_migration_provenance';

    public function up(): void
    {
        DB::transaction(function (): void {
            if (! Schema::hasTable(self::PROVENANCE_TABLE)) {
                Schema::create(self::PROVENANCE_TABLE, function (Blueprint $table): void {
                    $table->ulid('vacation_id')->primary();
                    $table->string('original_status', 30);
                    $table->char('vacation_fingerprint', 64);
                    $table->ulid('override_id');
                    $table->boolean('override_created');
                    $table->char('override_fingerprint', 64);
                });
            }

            DB::table('user_vacations')
                ->whereIn('status', ['scheduled', 'active'])
                ->orderBy('id')
                ->get()
                ->each(function (object $vacation): void {
                    $override = DB::table('availability_overrides')
                        ->where('user_id', $vacation->user_id)
                        ->whereDate('starts_at', $vacation->starts_at)
                        ->whereDate('ends_at', $vacation->ends_at)
                        ->where('day_part', 'all_day')
                        ->orderBy('id')
                        ->first();
                    $overrideCreated = false;

                    if ($override === null) {
                        $overrideId = (string) $vacation->id;
                        while (DB::table('availability_overrides')->where('id', $overrideId)->exists()) {
                            $overrideId = (string) Str::ulid();
                        }

                        DB::table('availability_overrides')->insert([
                            'id' => $overrideId,
                            'user_id' => $vacation->user_id,
                            'starts_at' => $vacation->starts_at,
                            'ends_at' => $vacation->ends_at,
                            'day_part' => 'all_day',
                            'is_available' => false,
                            'note' => $vacation->note === null
                                ? null
                                : Str::limit((string) $vacation->note, 1000, ''),
                            'created_by' => $vacation->created_by,
                            'created_at' => $vacation->created_at,
                            'updated_at' => $vacation->updated_at,
                        ]);

                        $override = DB::table('availability_overrides')
                            ->where('id', $overrideId)
                            ->firstOrFail();
                        $overrideCreated = true;
                    }

                    DB::table(self::PROVENANCE_TABLE)->insert([
                        'vacation_id' => $vacation->id,
                        'original_status' => $vacation->status,
                        'vacation_fingerprint' => $this->vacationFingerprint($vacation),
                        'override_id' => $override->id,
                        'override_created' => $overrideCreated,
                        'override_fingerprint' => $this->overrideFingerprint($override),
                    ]);

                    DB::table('user_vacations')
                        ->where('id', $vacation->id)
                        ->update(['status' => 'migrated']);
                });
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::PROVENANCE_TABLE)) {
            throw new RuntimeException(
                'Vacation availability migration provenance is missing; rollback cannot be verified safely.',
            );
        }

        DB::transaction(function (): void {
            $provenance = DB::table(self::PROVENANCE_TABLE)
                ->orderBy('vacation_id')
                ->lockForUpdate()
                ->get();

            foreach ($provenance as $record) {
                $vacation = DB::table('user_vacations')
                    ->where('id', $record->vacation_id)
                    ->lockForUpdate()
                    ->first();
                if (
                    $vacation === null
                    || $vacation->status !== 'migrated'
                    || ! hash_equals($record->vacation_fingerprint, $this->vacationFingerprint($vacation))
                ) {
                    throw new RuntimeException(
                        "Vacation {$record->vacation_id} changed after migration; rollback was refused before any data was restored.",
                    );
                }

                $override = DB::table('availability_overrides')
                    ->where('id', $record->override_id)
                    ->lockForUpdate()
                    ->first();
                if (
                    $override === null
                    || ! hash_equals($record->override_fingerprint, $this->overrideFingerprint($override))
                ) {
                    throw new RuntimeException(
                        "Availability override {$record->override_id} changed or was deleted after migration; rollback was refused before any data was restored.",
                    );
                }
            }

            foreach ($provenance as $record) {
                DB::table('user_vacations')
                    ->where('id', $record->vacation_id)
                    ->update(['status' => $record->original_status]);
            }

            $createdOverrideIds = $provenance
                ->filter(fn (object $record): bool => $this->booleanValue($record->override_created))
                ->pluck('override_id')
                ->unique()
                ->values();
            if ($createdOverrideIds->isNotEmpty()) {
                DB::table('availability_overrides')
                    ->whereIn('id', $createdOverrideIds)
                    ->delete();
            }

            Schema::drop(self::PROVENANCE_TABLE);
        });
    }

    private function vacationFingerprint(object $vacation): string
    {
        return $this->fingerprint([
            'id' => (string) $vacation->id,
            'user_id' => (string) $vacation->user_id,
            'starts_at' => (string) $vacation->starts_at,
            'ends_at' => (string) $vacation->ends_at,
            'note' => $vacation->note === null ? null : (string) $vacation->note,
            'created_by' => $vacation->created_by === null ? null : (string) $vacation->created_by,
            'cancelled_by' => $vacation->cancelled_by === null ? null : (string) $vacation->cancelled_by,
            'cancelled_at' => $vacation->cancelled_at === null ? null : (string) $vacation->cancelled_at,
            'created_at' => (string) $vacation->created_at,
            'updated_at' => (string) $vacation->updated_at,
        ]);
    }

    private function overrideFingerprint(object $override): string
    {
        return $this->fingerprint([
            'id' => (string) $override->id,
            'user_id' => (string) $override->user_id,
            'starts_at' => (string) $override->starts_at,
            'ends_at' => (string) $override->ends_at,
            'day_part' => (string) $override->day_part,
            'is_available' => $this->booleanValue($override->is_available),
            'note' => $override->note === null ? null : (string) $override->note,
            'created_by' => $override->created_by === null ? null : (string) $override->created_by,
            'created_at' => (string) $override->created_at,
            'updated_at' => (string) $override->updated_at,
        ]);
    }

    /**
     * @param  array<string, bool|string|null>  $values
     */
    private function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_string($value)) {
            return match (strtolower($value)) {
                '', '0', 'f', 'false', 'no', 'off' => false,
                '1', 't', 'true', 'yes', 'on' => true,
                default => (bool) $value,
            };
        }

        return (bool) $value;
    }
};
