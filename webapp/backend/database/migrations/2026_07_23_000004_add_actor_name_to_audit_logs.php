<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('actor_name')->nullable()->after('actor_id');
        });

        DB::statement(<<<'SQL'
            UPDATE audit_logs AS audit
            SET actor_name = users.name
            FROM users
            WHERE audit.actor_id = users.id
              AND (audit.actor_name IS NULL OR audit.actor_name = '')
            SQL);
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn('actor_name');
        });
    }
};
