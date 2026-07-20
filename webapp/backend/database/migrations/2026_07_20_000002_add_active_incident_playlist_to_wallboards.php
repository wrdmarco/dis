<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallboards', function (Blueprint $table): void {
            $table->foreignUlid('active_incident_playlist_id')
                ->nullable()
                ->after('playlist_id')
                ->constrained('wallboard_playlists')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallboards', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_incident_playlist_id');
        });
    }
};
