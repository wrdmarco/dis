<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->ulid('personal_access_token_id')->nullable()->after('token_hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->dropIndex(['personal_access_token_id']);
            $table->dropColumn('personal_access_token_id');
        });
    }
};
