<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('knmi_forecast_operations')) {
            DB::table('knmi_forecast_operations')->delete();
        }
        if (Schema::hasTable('knmi_forecast_snapshots')) {
            DB::table('knmi_forecast_snapshots')->delete();
        }
        if (Schema::hasTable('weather_dataset_operations')) {
            DB::table('weather_dataset_operations')->delete();
        }
        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')
                ->whereIn('connection', ['knmi', 'knmi_realtime'])
                ->orWhereIn('queue', ['knmi', 'knmi-realtime'])
                ->delete();
        }
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->whereIn('key', [
                    'weather.knmi_open_data_api_key',
                ])
                ->delete();
        }

        if (Schema::hasTable('permissions') && Schema::hasTable('permission_role')) {
            $legacyPermissionId = DB::table('permissions')
                ->where('name', 'knmi.manage')
                ->value('id');
            $replacementPermissionIds = DB::table('permissions')
                ->whereIn('name', ['operational-weather.view', 'uav-forecast.view'])
                ->pluck('id');

            if ($legacyPermissionId !== null && $replacementPermissionIds->count() === 2) {
                $roleIds = DB::table('permission_role')
                    ->where('permission_id', $legacyPermissionId)
                    ->pluck('role_id');

                foreach ($roleIds as $roleId) {
                    foreach ($replacementPermissionIds as $replacementPermissionId) {
                        DB::table('permission_role')->updateOrInsert(
                            [
                                'role_id' => $roleId,
                                'permission_id' => $replacementPermissionId,
                            ],
                            ['created_at' => now()],
                        );
                    }
                }

                DB::table('permission_role')->where('permission_id', $legacyPermissionId)->delete();
                DB::table('permissions')->where('id', $legacyPermissionId)->delete();
            }
        }
    }

    public function down(): void
    {
        // Retired snapshots, credentials and management permissions cannot be reconstructed safely.
    }
};
