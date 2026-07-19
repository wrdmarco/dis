<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DispatchPushOutbox;
use App\Models\LocationUpdate;
use App\Models\PushDeliveryLog;
use App\Models\SystemSetting;
use App\Models\WallboardPairingRequest;
use App\Models\WallboardSession;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

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
        $expiredWallboardSessions = WallboardSession::query()
            ->where(fn ($query) => $query
                ->where('expires_at', '<', now())
                ->orWhere(fn ($revoked) => $revoked
                    ->whereNotNull('revoked_at')
                    ->where('revoked_at', '<', now()->subDay())));
        $expiredWallboardPairings = WallboardPairingRequest::query()
            ->where(fn ($query) => $query
                ->where('expires_at', '<', now())
                ->orWhere(fn ($consumed) => $consumed
                    ->whereNotNull('consumed_at')
                    ->where('consumed_at', '<', now()->subDay())));

        $counts = [
            'location_updates' => LocationUpdate::query()->where('created_at', '<', $locationCutoff)->count(),
            'push_delivery_logs' => PushDeliveryLog::query()->where('created_at', '<', $pushCutoff)->count(),
            'completed_dispatch_push_outbox' => $this->completedOutboxBefore($pushCutoff)->count(),
            'audit_logs' => AuditLog::query()->where('created_at', '<', $auditCutoff)->count(),
            'wallboard_sessions' => (clone $expiredWallboardSessions)->count(),
            'wallboard_pairing_requests' => (clone $expiredWallboardPairings)->count(),
        ];

        if (! $dryRun) {
            LocationUpdate::query()->where('created_at', '<', $locationCutoff)->delete();
            PushDeliveryLog::query()->where('created_at', '<', $pushCutoff)->delete();
            $this->completedOutboxBefore($pushCutoff)->delete();
            AuditLog::query()->where('created_at', '<', $auditCutoff)->delete();
            $expiredWallboardSessions->delete();
            $expiredWallboardPairings->delete();
        }

        $this->info(json_encode(['dry_run' => $dryRun, 'counts' => $counts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function completedOutboxBefore(DateTimeInterface $cutoff): Builder
    {
        return DispatchPushOutbox::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($query) => $query
                ->whereNotNull('delivered_at')
                ->orWhereNotNull('cancelled_at'));
    }
}
