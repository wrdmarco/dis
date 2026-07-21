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
            $table->unsignedTinyInteger('processing_progress')->nullable();
        });

        DB::table('wallboard_media_assets')
            ->where('status', 'ready')
            ->update(['processing_progress' => 100]);
        DB::table('wallboard_media_assets')
            ->where('status', 'processing')
            ->update(['processing_progress' => 0]);
        DB::table('wallboard_media_assets')
            ->where('status', 'failed')
            ->update(['processing_progress' => null]);
    }

    public function down(): void
    {
        Schema::table('wallboard_media_assets', function (Blueprint $table): void {
            $table->dropColumn('processing_progress');
        });
    }
};
