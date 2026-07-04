<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('type')->index();
            $table->timestampTz('starts_at')->index();
            $table->timestampTz('ends_at')->nullable()->index();
            $table->string('location_label')->nullable();
            $table->text('description')->nullable();
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['team_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
