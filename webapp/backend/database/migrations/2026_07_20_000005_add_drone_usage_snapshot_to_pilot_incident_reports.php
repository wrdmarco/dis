<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pilot_incident_reports', function (Blueprint $table): void {
            $table->jsonb('drone_usage_snapshot')->nullable()->after('custom_fields');
        });
    }

    public function down(): void
    {
        Schema::table('pilot_incident_reports', function (Blueprint $table): void {
            $table->dropColumn('drone_usage_snapshot');
        });
    }
};
