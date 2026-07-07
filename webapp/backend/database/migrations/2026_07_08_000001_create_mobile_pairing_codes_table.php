<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_pairing_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('client_type', 30)->index();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable()->index();
            $table->string('consumed_ip', 64)->nullable();
            $table->string('consumed_user_agent', 512)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_pairing_codes');
    }
};
