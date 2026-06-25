<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('certifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_required_for_dispatch')->default(false)->index();
            $table->unsignedSmallInteger('warning_days_before_expiry')->default(30);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('user_certifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('certification_id')->constrained('certifications')->restrictOnDelete();
            $table->date('issued_at');
            $table->date('expires_at')->nullable()->index();
            $table->string('certificate_number')->nullable();
            $table->string('status')->default('active')->index();
            $table->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'certification_id', 'certificate_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_certifications');
        Schema::dropIfExists('certifications');
    }
};

