<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_pairing_codes', function (Blueprint $table): void {
            $table->string('review_mode', 40)->nullable()->index()->after('client_type');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_pairing_codes', function (Blueprint $table): void {
            $table->dropColumn('review_mode');
        });
    }
};
