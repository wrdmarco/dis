<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_versions', function (Blueprint $table): void {
            $table->string('application_id')->default('nl.wrdmarco.dis')->after('platform')->index();
            $table->dropUnique(['platform', 'version_code']);
            $table->unique(['platform', 'application_id', 'version_code']);
        });
    }

    public function down(): void
    {
        Schema::table('app_versions', function (Blueprint $table): void {
            $table->dropUnique(['platform', 'application_id', 'version_code']);
            $table->dropColumn('application_id');
            $table->unique(['platform', 'version_code']);
        });
    }
};
