<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class SystemSelfCheck extends Command
{
    private const REQUIRED_TABLES = [
        'users',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'roles',
        'permissions',
        'permission_role',
        'role_user',
        'teams',
        'team_user',
        'incidents',
        'incident_assignments',
        'incident_status_history',
        'dispatch_requests',
        'dispatch_recipients',
        'availability_statuses',
        'assets',
        'asset_assignments',
        'certifications',
        'user_certifications',
        'fcm_tokens',
        'push_delivery_logs',
        'location_sharing_consents',
        'location_updates',
        'app_versions',
        'audit_logs',
        'system_settings',
        'personal_access_tokens',
    ];

    protected $signature = 'dis:self-check';

    protected $description = 'Run local DIS operational self-checks for deployment and monitoring.';

    public function handle(): int
    {
        DB::connection()->getPdo();

        $missingTables = array_values(array_filter(
            self::REQUIRED_TABLES,
            fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missingTables !== []) {
            $this->error('DIS database schema is incomplete. Missing tables: '.implode(', ', $missingTables));
            return self::FAILURE;
        }

        Cache::put('self-check', 'ok', 30);
        Storage::disk('local')->put('self-check.txt', 'ok');
        $storageOk = Storage::disk('local')->get('self-check.txt') === 'ok';
        Storage::disk('local')->delete('self-check.txt');

        if (Cache::get('self-check') !== 'ok' || ! $storageOk) {
            $this->error('DIS self-check failed.');
            return self::FAILURE;
        }

        $this->info('DIS self-check passed.');

        return self::SUCCESS;
    }
}
