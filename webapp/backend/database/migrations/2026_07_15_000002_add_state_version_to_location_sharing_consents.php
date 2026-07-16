<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_sharing_consents', function (Blueprint $table): void {
            $table->unsignedBigInteger('state_version')->default(1)->after('is_active');
        });
        Schema::table('location_updates', function (Blueprint $table): void {
            // Existing coordinates belong to the initial consent generation.
            // Every later consent transition increments the generation, so a
            // coordinate from an earlier grant can never become live again.
            $table->unsignedBigInteger('consent_state_version')->default(1)->after('user_id');
            $table->index(
                ['incident_id', 'user_id', 'created_at', 'id'],
                'location_updates_latest_receipt_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('location_updates', function (Blueprint $table): void {
            $table->dropIndex('location_updates_latest_receipt_idx');
            $table->dropColumn('consent_state_version');
        });
        Schema::table('location_sharing_consents', function (Blueprint $table): void {
            $table->dropColumn('state_version');
        });
    }
};
