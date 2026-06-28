<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->boolean('can_use_operator_app')->default(true)->after('requires_two_factor');
            $table->boolean('can_use_admin_app')->default(false)->after('can_use_operator_app');
        });

        DB::table('roles')
            ->whereIn('name', ['system-administrator', 'national-coordinator', 'incident-coordinator'])
            ->update(['can_use_admin_app' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['can_use_operator_app', 'can_use_admin_app']);
        });
    }
};
