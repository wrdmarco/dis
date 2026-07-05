<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->json('custom_fields')->nullable()->after('required_resources');
        });

        Schema::table('pilot_incident_reports', function (Blueprint $table): void {
            $table->json('custom_fields')->nullable()->after('flight_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('pilot_incident_reports', function (Blueprint $table): void {
            $table->dropColumn('custom_fields');
        });

        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn('custom_fields');
        });
    }
};
