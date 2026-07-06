<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->timestampTz('report_finalized_at')->nullable()->after('report_generated_at');
        });

        DB::table('incidents')
            ->whereNotNull('report_pdf_path')
            ->whereNotNull('report_generated_at')
            ->whereNull('report_finalized_at')
            ->update(['report_finalized_at' => DB::raw('report_generated_at')]);
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn('report_finalized_at');
        });
    }
};
