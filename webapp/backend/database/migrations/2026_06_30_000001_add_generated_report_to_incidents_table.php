<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('report_pdf_path')->nullable()->after('drone_flight_context');
            $table->timestampTz('report_generated_at')->nullable()->after('report_pdf_path');
            $table->text('report_generation_error')->nullable()->after('report_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn(['report_pdf_path', 'report_generated_at', 'report_generation_error']);
        });
    }
};
