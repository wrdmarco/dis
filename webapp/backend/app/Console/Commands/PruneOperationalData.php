<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DispatchPushOutbox;
use App\Models\FcmToken;
use App\Models\LocationUpdate;
use App\Models\PushDeliveryLog;
use App\Models\PushQueueWorkItem;
use App\Models\SystemSetting;
use App\Models\WallboardPairingRequest;
use App\Models\WallboardSession;
use App\Models\WeatherDatasetOperation;
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
        $weatherDatasetOperationCutoff = now()->subDays(
            max(1, (int) config('dis.retention.weather_dataset_operations_days', 14)),
        );
        $pushQueueWorkCutoff = now()->subDays(
            max(1, (int) config('dis.retention.push_queue_work_items_days', 7)),
        );
        $expiredRevokedPushTokens = FcmToken::query()
            ->where('is_active', false)
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', now()->subDay())
            ->whereNotExists(fn ($outbox) => $outbox
                ->selectRaw('1')
                ->from('dispatch_push_outbox')
                ->whereColumn('dispatch_push_outbox.fcm_token_id', 'fcm_tokens.id'));
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
            'revoked_push_tokens' => (clone $expiredRevokedPushTokens)->count(),
            'completed_dispatch_push_outbox' => $this->completedOutboxBefore($pushCutoff)->count(),
            'terminal_push_queue_work_items' => $this->terminalPushQueueWorkBefore($pushQueueWorkCutoff)->count(),
            'audit_logs' => AuditLog::query()->where('created_at', '<', $auditCutoff)->count(),
            'weather_dataset_operations' => $this->terminalWeatherDatasetOperationsBefore(
                $weatherDatasetOperationCutoff,
            )->count(),
            'wallboard_sessions' => (clone $expiredWallboardSessions)->count(),
            'wallboard_pairing_requests' => (clone $expiredWallboardPairings)->count(),
        ];

        if (! $dryRun) {
            LocationUpdate::query()->where('created_at', '<', $locationCutoff)->delete();
            PushDeliveryLog::query()->where('created_at', '<', $pushCutoff)->delete();
            $expiredRevokedPushTokens->delete();
            $this->completedOutboxBefore($pushCutoff)->delete();
            $this->terminalPushQueueWorkBefore($pushQueueWorkCutoff)->delete();
            AuditLog::query()->where('created_at', '<', $auditCutoff)->delete();
            $this->terminalWeatherDatasetOperationsBefore($weatherDatasetOperationCutoff)->delete();
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

    private function terminalPushQueueWorkBefore(DateTimeInterface $cutoff): Builder
    {
        return PushQueueWorkItem::query()
            ->whereIn('status', [
                PushQueueWorkItem::STATUS_COMPLETED,
                PushQueueWorkItem::STATUS_FAILED,
            ])
            ->where('finished_at', '<', $cutoff);
    }

    private function terminalWeatherDatasetOperationsBefore(DateTimeInterface $cutoff): Builder
    {
        return WeatherDatasetOperation::query()
            ->whereNull('active_key')
            ->whereIn('state', [
                WeatherDatasetOperation::STATE_SUCCEEDED,
                WeatherDatasetOperation::STATE_FAILED,
            ])
            ->where('created_at', '<', $cutoff);
    }
}
