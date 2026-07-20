<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('province_code', 2)->nullable()->index();
            $table->string('province_name')->nullable();
            $table->string('province_source', 100)->nullable();
            $table->timestampTz('province_resolved_at')->nullable()->index();
            $table->string('country_code', 2)->nullable()->index();
            $table->string('country_name')->nullable();
            $table->string('country_source', 100)->nullable();
            $table->timestampTz('country_resolved_at')->nullable()->index();
            $table->timestampTz('location_enrichment_attempted_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn([
                'province_code',
                'province_name',
                'province_source',
                'province_resolved_at',
                'country_code',
                'country_name',
                'country_source',
                'country_resolved_at',
                'location_enrichment_attempted_at',
            ]);
        });
    }
};
