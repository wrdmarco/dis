<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('osrm_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('request_id', 32)->unique();
            $table->string('action', 32);
            $table->string('state', 24)->index();
            $table->string('stage', 32);
            $table->string('active_key', 32)->nullable()->unique();
            $table->text('message');
            $table->unsignedSmallInteger('progress_percent')->nullable();
            $table->text('source_url');
            $table->char('source_sha256', 64);
            $table->decimal('health_longitude', 10, 7);
            $table->decimal('health_latitude', 10, 7);
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('actor_id_snapshot', 26);
            $table->integer('exit_code')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['created_at', 'id']);
        });

        $now = Carbon::now();
        $permissionId = (string) (DB::table('permissions')
            ->where('name', 'system.routing.manage')
            ->value('id') ?? Str::ulid());
        DB::table('permissions')->updateOrInsert(
            ['name' => 'system.routing.manage'],
            [
                'id' => $permissionId,
                'display_name' => 'OSRM-routering beheren',
                'category' => 'system_configuration',
                'description' => 'Installeer, activeer en werk de lokale OSRM-routeringsdata bij.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $systemAdministratorId = DB::table('roles')
            ->where('name', 'system-administrator')
            ->value('id');
        if (is_string($systemAdministratorId)) {
            DB::table('permission_role')->updateOrInsert(
                [
                    'permission_id' => $permissionId,
                    'role_id' => $systemAdministratorId,
                ],
                ['created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('osrm_operations');

        $permissionId = DB::table('permissions')
            ->where('name', 'system.routing.manage')
            ->value('id');
        if (is_string($permissionId)) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
