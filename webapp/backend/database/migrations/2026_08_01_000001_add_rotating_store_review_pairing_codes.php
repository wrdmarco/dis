<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_pairing_codes', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable()->change();
            $table->text('review_code')->nullable()->after('review_mode');
            $table->string('active_review_slot', 20)->nullable()->unique()->after('review_code');
        });
    }

    public function down(): void
    {
        DB::table('mobile_pairing_codes')->whereNull('expires_at')->delete();

        Schema::table('mobile_pairing_codes', function (Blueprint $table): void {
            $table->dropUnique(['active_review_slot']);
            $table->dropColumn(['review_code', 'active_review_slot']);
        });

        Schema::table('mobile_pairing_codes', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable(false)->change();
        });
    }
};
