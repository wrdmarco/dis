<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\LocationUpdate;
use App\Models\PushDeliveryLog;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

final class PruneOperationalData extends Command
{
    protected $signature = 'dis:prune-operational-data {--dry-run}';

    protected $description = 'Prune operational data according to configured retention windows.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $locationCutoff = now()->subDays(SystemSetting::integer('retention.location_days', (int) config('dis.location.default_retention_days', 30)));
        $pushCutoff = now()->subDays(SystemSetting::integer('retention.push_logs_days', (int) config('dis.retention.push_logs_days', 90)));
        $auditCutoff = now()->subDays(SystemSetting::integer('retention.audit_logs_days', (int) config('dis.retention.audit_logs_days', 3650)));

        $counts = [
            'location_updates' => LocationUpdate::query()->where('created_at', '<', $locationCutoff)->count(),
            'push_delivery_logs' => PushDeliveryLog::query()->where('created_at', '<', $pushCutoff)->count(),
            'audit_logs' => AuditLog::query()->where('created_at', '<', $auditCutoff)->count(),
        ];

        if (! $dryRun) {
            LocationUpdate::query()->where('created_at', '<', $locationCutoff)->delete();
            PushDeliveryLog::query()->where('created_at', '<', $pushCutoff)->delete();
            AuditLog::query()->where('created_at', '<', $auditCutoff)->delete();
        }

        $this->info(json_encode(['dry_run' => $dryRun, 'counts' => $counts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
