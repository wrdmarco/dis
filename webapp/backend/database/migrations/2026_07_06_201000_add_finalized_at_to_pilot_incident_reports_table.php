<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pilot_incident_reports', function (Blueprint $table): void {
            $table->timestampTz('finalized_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('pilot_incident_reports', function (Blueprint $table): void {
            $table->dropColumn('finalized_at');
        });
    }
};
