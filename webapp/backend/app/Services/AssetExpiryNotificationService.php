<?php

namespace App\Services;

use App\Mail\AssetExpiryMail;
use App\Models\Asset;
use App\Models\AssetMailLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Mail;

final class AssetExpiryNotificationService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @return array{checked: int, sent: int, skipped: int, failed: int}
     */
    public function sendDueNotifications(bool $dryRun = false): array
    {
        $today = now()->toImmutable()->startOfDay();
        $warningDays = SystemSetting::integer('asset.warning_days_before_expiry', 30);
        $downloadUrl = rtrim(SystemSetting::string('app.public_url', config('app.url', '')) ?? '', '/');

        $candidates = Asset::query()
            ->with(['activeAssignment.user'])
            ->whereNotNull('maintenance_due_at')
            ->whereDate('maintenance_due_at', '<=', $today->addDays($warningDays)->toDateString())
            ->where('status', '!=', 'retired')
            ->get();

        $result = ['checked' => $candidates->count(), 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($candidates as $asset) {
            $expiresAt = $asset->maintenance_due_at;
            $user = $asset->activeAssignment?->user;

            if ($expiresAt === null || $user === null || $user->account_status !== 'active' || $user->email === '') {
                $result['skipped']++;
                continue;
            }

            $daysUntilExpiry = (int) $today->diffInDays($expiresAt, false);
            if (! in_array($daysUntilExpiry, [$warningDays, 0], true)) {
                $result['skipped']++;
                continue;
            }

            $notificationType = $daysUntilExpiry === 0 ? 'expired' : 'expiring';
            if ($this->alreadySentToday($asset, $user->id, $notificationType)) {
                $result['skipped']++;
                continue;
            }

            if ($dryRun) {
                $result['sent']++;
                continue;
            }

            try {
                Mail::to($user->email)->send(new AssetExpiryMail($asset, $user, $daysUntilExpiry, $downloadUrl));
                AssetMailLog::query()->create([
                    'asset_id' => $asset->id,
                    'user_id' => $user->id,
                    'notification_type' => $notificationType,
                    'expires_at' => $expiresAt->toDateString(),
                    'sent_for_date' => $today->toDateString(),
                    'sent_at' => now(),
                ]);
                $this->auditService->record('assets.expiry_mail_sent', $asset, null, [
                    'user_id' => $user->id,
                    'expires_at' => $expiresAt->toDateString(),
                    'days_until_expiry' => $daysUntilExpiry,
                    'notification_type' => $notificationType,
                ]);
                $result['sent']++;
            } catch (\Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function alreadySentToday(Asset $asset, string $userId, string $notificationType): bool
    {
        return AssetMailLog::query()
            ->where('asset_id', $asset->id)
            ->where('user_id', $userId)
            ->where('notification_type', $notificationType)
            ->whereDate('expires_at', $asset->maintenance_due_at?->toDateString())
            ->whereDate('sent_for_date', now()->toDateString())
            ->exists();
    }
}
