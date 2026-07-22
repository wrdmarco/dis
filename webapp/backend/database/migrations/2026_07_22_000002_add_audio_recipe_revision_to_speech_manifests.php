<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_AUDIO_RECIPE_REVISION = 'legacy-segmented-v1';

    public function up(): void
    {
        Schema::table('speech_manifest_builds', function (Blueprint $table): void {
            $table->string('audio_recipe_revision', 120)
                ->nullable();
        });
        Schema::table('speech_manifests', function (Blueprint $table): void {
            $table->string('audio_recipe_revision', 120)
                ->nullable();
        });

        DB::table('speech_manifest_builds')->whereNull('audio_recipe_revision')->update([
            'audio_recipe_revision' => self::LEGACY_AUDIO_RECIPE_REVISION,
        ]);
        DB::table('speech_manifests')->whereNull('audio_recipe_revision')->update([
            'audio_recipe_revision' => self::LEGACY_AUDIO_RECIPE_REVISION,
        ]);

        Schema::table('speech_manifest_builds', function (Blueprint $table): void {
            $table->string('audio_recipe_revision', 120)
                ->nullable(false)
                ->change();
        });
        Schema::table('speech_manifests', function (Blueprint $table): void {
            $table->string('audio_recipe_revision', 120)
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('speech_manifests', function (Blueprint $table): void {
            $table->dropColumn('audio_recipe_revision');
        });
        Schema::table('speech_manifest_builds', function (Blueprint $table): void {
            $table->dropColumn('audio_recipe_revision');
        });
    }
};
