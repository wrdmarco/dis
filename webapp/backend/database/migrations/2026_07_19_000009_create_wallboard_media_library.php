<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallboard_media_coordination_locks', function (Blueprint $table): void {
            $table->string('scope', 40)->primary();
            $table->timestampTz('created_at');
        });
        DB::table('wallboard_media_coordination_locks')->insert([
            'scope' => 'library',
            'created_at' => now(),
        ]);

        Schema::create('wallboard_media_folders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('parent_id')->nullable();
            $table->char('parent_scope', 26);
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['parent_scope', 'normalized_name'], 'wb_media_folder_sibling_unique');
            $table->index('parent_id', 'wb_media_folder_parent_idx');
        });

        // PostgreSQL must see the completed primary-key constraint before a
        // table can reference itself. Adding this FK in the CREATE TABLE
        // statement is driver-order dependent.
        Schema::table('wallboard_media_folders', function (Blueprint $table): void {
            $table->foreign('parent_id', 'wb_media_folder_parent_fk')
                ->references('id')
                ->on('wallboard_media_folders')
                ->restrictOnDelete();
        });

        Schema::create('wallboard_media_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('folder_id')->nullable();
            $table->string('display_name', 180);
            $table->string('original_name', 255);
            $table->string('storage_path', 255)->unique();
            $table->char('sha256', 64)->index();
            $table->string('mime_type', 40);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('status', 20)->default('processing')->index();
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['folder_id', 'deleted_at'], 'wb_media_asset_folder_idx');
            $table->foreign('folder_id', 'wb_media_asset_folder_fk')
                ->references('id')
                ->on('wallboard_media_folders')
                ->nullOnDelete();
        });

        Schema::create('wallboard_media_playlists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('name');
        });

        Schema::create('wallboard_media_playlist_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('media_playlist_id');
            $table->foreignUlid('media_asset_id');
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();

            $table->unique(['media_playlist_id', 'position'], 'wb_media_item_position_unique');
            $table->unique(['media_playlist_id', 'media_asset_id'], 'wb_media_item_asset_unique');
            $table->index('media_asset_id', 'wb_media_item_asset_idx');
            $table->foreign('media_playlist_id', 'wb_media_item_playlist_fk')
                ->references('id')
                ->on('wallboard_media_playlists')
                ->cascadeOnDelete();
            $table->foreign('media_asset_id', 'wb_media_item_asset_fk')
                ->references('id')
                ->on('wallboard_media_assets')
                ->restrictOnDelete();
        });

        Schema::create('wallboard_media_playlist_usages', function (Blueprint $table): void {
            // This is a rebuildable projection of wallboard playlist JSON. It
            // deliberately has no FK to wallboard_playlists so historical
            // playlist migrations remain independently reversible. The shared
            // coordination lock and synchronizer own its lifecycle.
            $table->ulid('wallboard_playlist_id');
            $table->string('page_id', 64);
            $table->foreignUlid('media_playlist_id');
            $table->timestampsTz();

            $table->primary(['wallboard_playlist_id', 'page_id'], 'wb_media_usage_primary');
            $table->index('media_playlist_id', 'wb_media_usage_media_idx');
            $table->foreign('media_playlist_id', 'wb_media_usage_media_playlist_fk')
                ->references('id')
                ->on('wallboard_media_playlists')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallboard_media_playlist_usages');
        Schema::dropIfExists('wallboard_media_playlist_items');
        Schema::dropIfExists('wallboard_media_playlists');
        Schema::dropIfExists('wallboard_media_assets');
        Schema::dropIfExists('wallboard_media_folders');
        Schema::dropIfExists('wallboard_media_coordination_locks');
    }
};
