<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incident_team', function (Blueprint $table): void {
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['incident_id', 'team_id']);
        });

        DB::table('incidents')
            ->whereNotNull('team_id')
            ->orderBy('id')
            ->select(['id', 'team_id'])
            ->chunk(500, function ($incidents): void {
                foreach ($incidents as $incident) {
                    DB::table('incident_team')->updateOrInsert([
                        'incident_id' => $incident->id,
                        'team_id' => $incident->team_id,
                    ], [
                        'created_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_team');
    }
};
