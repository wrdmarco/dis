<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('team_alert_team', function (Blueprint $table): void {
            $table->ulid('team_id');
            $table->ulid('alert_team_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['team_id', 'alert_team_id']);
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('alert_team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_alert_team');
    }
};
