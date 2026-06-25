<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->index();
            $table->ulid('parent_team_id')->nullable()->index();
            $table->boolean('is_operational')->default(true)->index();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->ulid('team_id');
            $table->ulid('user_id');
            $table->ulid('assigned_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['team_id', 'user_id']);
            $table->index('assigned_by');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->foreign('parent_team_id')->references('id')->on('teams')->nullOnDelete();
        });

        Schema::table('team_user', function (Blueprint $table): void {
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
