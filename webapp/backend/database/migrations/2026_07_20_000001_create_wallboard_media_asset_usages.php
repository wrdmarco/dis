<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallboard_media_asset_usages', function (Blueprint $table): void {
            // Rebuildable projection of direct media references in a
            // wallboard-playlist configuration. It is deliberately separate
            // from photo-playlist usage so videos can never enter a carousel.
            $table->foreignUlid('wallboard_playlist_id')
                ->constrained('wallboard_playlists')
                ->cascadeOnDelete();
            $table->string('page_id', 64);
            $table->foreignUlid('media_asset_id')
                ->constrained('wallboard_media_assets')
                ->restrictOnDelete();
            $table->timestampsTz();

            $table->primary(['wallboard_playlist_id', 'page_id'], 'wb_media_asset_usage_primary');
            $table->index('media_asset_id', 'wb_media_asset_usage_asset_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallboard_media_asset_usages');
    }
};
