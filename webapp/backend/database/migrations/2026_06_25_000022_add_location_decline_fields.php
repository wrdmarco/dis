<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('location_sharing_consents', function (Blueprint $table): void {
            $table->timestampTz('declined_at')->nullable()->after('revoked_at');
            $table->string('refusal_reason')->nullable()->after('declined_at');
        });
    }

    public function down(): void
    {
        Schema::table('location_sharing_consents', function (Blueprint $table): void {
            $table->dropColumn(['declined_at', 'refusal_reason']);
        });
    }
};
