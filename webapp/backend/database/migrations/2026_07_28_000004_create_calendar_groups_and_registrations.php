<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->boolean('is_everyone')->default(false);
            $table->foreignUlid('legacy_team_id')->nullable()->unique()->constrained('teams')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement(
            'CREATE UNIQUE INDEX calendar_groups_single_everyone '
            .'ON calendar_groups (is_everyone) WHERE is_everyone = true',
        );

        Schema::create('calendar_group_user', function (Blueprint $table): void {
            $table->foreignUlid('calendar_group_id')->constrained('calendar_groups')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['calendar_group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('calendar_group_team', function (Blueprint $table): void {
            $table->foreignUlid('calendar_group_id')->constrained('calendar_groups')->cascadeOnDelete();
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['calendar_group_id', 'team_id']);
            $table->index('team_id');
        });

        Schema::create('calendar_event_group', function (Blueprint $table): void {
            $table->foreignUlid('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignUlid('calendar_group_id')->constrained('calendar_groups')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['calendar_event_id', 'calendar_group_id']);
            $table->index('calendar_group_id');
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->enum('audience_scope', ['everyone', 'groups'])->default('everyone')->index();
            $table->boolean('registration_enabled')->default(false)->index();
            $table->unsignedInteger('max_participants')->nullable();
        });

        DB::statement(
            'ALTER TABLE calendar_events ADD CONSTRAINT calendar_events_max_participants_positive '
            .'CHECK (max_participants IS NULL OR max_participants > 0)',
        );

        Schema::create('calendar_event_registrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name');
            $table->enum('status', ['registered', 'cancelled'])->default('registered');
            $table->foreignUlid('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('registered_by_name');
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_name')->nullable();
            $table->timestampTz('registered_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['calendar_event_id', 'user_id']);
            $table->index(['calendar_event_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        $this->seedAndBackfillAudiences();
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_registrations');

        DB::statement(
            'ALTER TABLE calendar_events DROP CONSTRAINT IF EXISTS calendar_events_max_participants_positive',
        );

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropColumn(['audience_scope', 'registration_enabled', 'max_participants']);
        });

        Schema::dropIfExists('calendar_event_group');
        Schema::dropIfExists('calendar_group_team');
        Schema::dropIfExists('calendar_group_user');
        Schema::dropIfExists('calendar_groups');
    }

    private function seedAndBackfillAudiences(): void
    {
        $now = Carbon::now();
        $everyoneGroupId = (string) Str::ulid();

        DB::table('calendar_groups')->insert([
            'id' => $everyoneGroupId,
            'name' => 'Iedereen',
            'description' => 'Beschermde systeemgroep voor organisatiebrede agenda-items.',
            'is_everyone' => true,
            'legacy_team_id' => null,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $everyoneEventIds = DB::table('calendar_events')
            ->whereNull('team_id')
            ->pluck('id');

        foreach ($everyoneEventIds as $eventId) {
            DB::table('calendar_event_group')->insert([
                'calendar_event_id' => (string) $eventId,
                'calendar_group_id' => $everyoneGroupId,
                'created_at' => $now,
            ]);
        }

        DB::table('calendar_events')
            ->whereNull('team_id')
            ->update(['audience_scope' => 'everyone']);

        $teams = DB::table('calendar_events')
            ->join('teams', 'teams.id', '=', 'calendar_events.team_id')
            ->whereNotNull('calendar_events.team_id')
            ->select(['teams.id', 'teams.name'])
            ->distinct()
            ->orderBy('teams.id')
            ->get();

        foreach ($teams as $team) {
            $groupId = (string) Str::ulid();

            DB::table('calendar_groups')->insert([
                'id' => $groupId,
                'name' => (string) $team->name,
                'description' => 'Automatisch overgenomen van een bestaand teamgebonden agenda-item.',
                'is_everyone' => false,
                'legacy_team_id' => (string) $team->id,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);

            DB::table('calendar_group_team')->insert([
                'calendar_group_id' => $groupId,
                'team_id' => (string) $team->id,
                'assigned_by' => null,
                'created_at' => $now,
            ]);

            $eventIds = DB::table('calendar_events')
                ->where('team_id', $team->id)
                ->pluck('id');

            foreach ($eventIds as $eventId) {
                DB::table('calendar_event_group')->insert([
                    'calendar_event_id' => (string) $eventId,
                    'calendar_group_id' => $groupId,
                    'created_at' => $now,
                ]);
            }

            DB::table('calendar_events')
                ->where('team_id', $team->id)
                ->update([
                    'audience_scope' => 'groups',
                    'updated_at' => $now,
                ]);
        }
    }
};
