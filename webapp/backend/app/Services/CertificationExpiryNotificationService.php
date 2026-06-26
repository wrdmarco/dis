<?php

namespace App\Services;

use App\Mail\CertificationExpiryMail;
use App\Models\CertificationMailLog;
use App\Models\SystemSetting;
use App\Models\UserCertification;
use Illuminate\Support\Facades\Mail;

final class CertificationExpiryNotificationService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @return array{checked: int, sent: int, skipped: int, failed: int}
     */
    public function sendDueNotifications(bool $dryRun = false): array
    {
        $today = now()->toImmutable()->startOfDay();
        $maxWarningDays = (int) max(1, UserCertification::query()
            ->join('certifications', 'certifications.id', '=', 'user_certifications.certification_id')
            ->max('certifications.warning_days_before_expiry') ?? 30);

        $candidates = UserCertification::query()
            ->with(['user', 'certification'])
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $today->addDays($maxWarningDays)->toDateString())
            ->whereHas('user', fn ($query) => $query->where('account_status', 'active'))
            ->get();

        $result = ['checked' => $candidates->count(), 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        $downloadUrl = rtrim(SystemSetting::string('app.public_url', config('app.url', '')) ?? '', '/').'/download';

        foreach ($candidates as $userCertification) {
            $expiresAt = $userCertification->expires_at;
            $certification = $userCertification->certification;
            $user = $userCertification->user;

            if ($expiresAt === null || $certification === null || $user === null || $user->email === '') {
                $result['skipped']++;
                continue;
            }

            $daysUntilExpiry = (int) $today->diffInDays($expiresAt, false);
            $warningDays = (int) $certification->warning_days_before_expiry;
            if ($daysUntilExpiry > $warningDays) {
                $result['skipped']++;
                continue;
            }

            $notificationType = $daysUntilExpiry < 0 ? 'expired' : 'expiring';
            if ($this->alreadySentToday($userCertification, $notificationType)) {
                $result['skipped']++;
                continue;
            }

            if ($dryRun) {
                $result['sent']++;
                continue;
            }

            try {
                Mail::to($user->email)->send(new CertificationExpiryMail($userCertification, $daysUntilExpiry, $downloadUrl));
                CertificationMailLog::query()->create([
                    'user_certification_id' => $userCertification->id,
                    'notification_type' => $notificationType,
                    'expires_at' => $expiresAt->toDateString(),
                    'sent_for_date' => $today->toDateString(),
                    'sent_at' => now(),
                ]);
                $this->auditService->record('certifications.expiry_mail_sent', $userCertification, null, [
                    'user_id' => $user->id,
                    'certification_id' => $certification->id,
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

    private function alreadySentToday(UserCertification $userCertification, string $notificationType): bool
    {
        return CertificationMailLog::query()
            ->where('user_certification_id', $userCertification->id)
            ->where('notification_type', $notificationType)
            ->whereDate('expires_at', $userCertification->expires_at?->toDateString())
            ->whereDate('sent_for_date', now()->toDateString())
            ->exists();
    }
}
