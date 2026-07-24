<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speech_cache_entries', function (Blueprint $table): void {
            $table->unsignedInteger('synthesis_duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('speech_cache_entries', function (Blueprint $table): void {
            $table->dropColumn('synthesis_duration_ms');
        });
    }
};
