<?php

namespace App\Console\Commands;

use App\Services\CertificationExpiryNotificationService;
use Illuminate\Console\Command;

final class SendCertificationExpiryNotifications extends Command
{
    protected $signature = 'dis:send-certification-expiry-mails {--dry-run}';

    protected $description = 'Mail users when their certifications are expired or within the configured warning window.';

    public function handle(CertificationExpiryNotificationService $notifications): int
    {
        $result = $notifications->sendDueNotifications((bool) $this->option('dry-run'));

        $this->info(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
