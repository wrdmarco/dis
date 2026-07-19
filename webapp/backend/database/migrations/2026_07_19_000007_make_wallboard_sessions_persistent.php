<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallboard_sessions', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable()->change();
        });

        DB::table('wallboard_sessions')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => null]);
    }

    public function down(): void
    {
        DB::table('wallboard_sessions')
            ->whereNull('expires_at')
            ->update(['expires_at' => now()->addDays(30)]);

        Schema::table('wallboard_sessions', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable(false)->change();
        });
    }
};
