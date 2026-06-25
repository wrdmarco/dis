<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('team_certification', function (Blueprint $table): void {
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('certification_id')->constrained('certifications')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['team_id', 'certification_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_certification');
    }
};
