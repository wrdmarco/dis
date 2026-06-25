<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('availability_statuses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->index();
            $table->boolean('is_available')->default(false)->index();
            $table->boolean('is_system_applied')->default(false);
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('effective_at')->useCurrent()->index();
            $table->timestampsTz();
            $table->index(['user_id', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_statuses');
    }
};

