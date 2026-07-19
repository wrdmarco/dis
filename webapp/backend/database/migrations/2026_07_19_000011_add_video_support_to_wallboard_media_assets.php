<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallboard_media_assets', function (Blueprint $table): void {
            $table->enum('kind', ['image', 'video'])->default('image')->index();
            $table->string('thumbnail_storage_path', 255)->nullable()->unique();
            $table->char('thumbnail_sha256', 64)->nullable();
            $table->string('thumbnail_mime_type', 40)->nullable();
            $table->unsignedBigInteger('thumbnail_byte_size')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable()->change();
            $table->unsignedInteger('height')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('wallboard_media_assets')->whereNull('width')->update(['width' => 0]);
        DB::table('wallboard_media_assets')->whereNull('height')->update(['height' => 0]);
        Schema::table('wallboard_media_assets', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropUnique(['thumbnail_storage_path']);
            $table->dropColumn([
                'kind',
                'thumbnail_storage_path',
                'thumbnail_sha256',
                'thumbnail_mime_type',
                'thumbnail_byte_size',
                'duration_seconds',
            ]);
            $table->unsignedInteger('width')->nullable(false)->change();
            $table->unsignedInteger('height')->nullable(false)->change();
        });
    }
};
