<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speech_cache_entries', function (Blueprint $table): void {
            $table->text('display_text')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('model_catalog_key', 80)->nullable()->index();
            $table->string('model_revision', 160)->nullable();
            $table->string('voice_design_revision', 120)->nullable();
            $table->string('audio_recipe_revision', 120)->nullable();
            $table->decimal('speed', 4, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('speech_cache_entries', function (Blueprint $table): void {
            $table->dropIndex(['model_catalog_key']);
            $table->dropColumn([
                'display_text',
                'locale',
                'model_catalog_key',
                'model_revision',
                'voice_design_revision',
                'audio_recipe_revision',
                'speed',
            ]);
        });
    }
};
