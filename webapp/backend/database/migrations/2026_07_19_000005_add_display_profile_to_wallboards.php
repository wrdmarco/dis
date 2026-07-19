<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallboards', function (Blueprint $table): void {
            // Keep historical migrations independent from application code that
            // may change after this release has been deployed.
            $table->enum('display_profile', ['auto', '1080p', '4k'])
                ->default('auto')
                ->after('layout');
        });
    }

    public function down(): void
    {
        Schema::table('wallboards', function (Blueprint $table): void {
            $table->dropColumn('display_profile');
        });
    }
};
