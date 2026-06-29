<?php

namespace App\Console\Commands;

use App\Services\AssetExpiryNotificationService;
use App\Services\CertificationExpiryNotificationService;
use Illuminate\Console\Command;

final class SendCertificationExpiryNotifications extends Command
{
    protected $signature = 'dis:send-certification-expiry-mails {--dry-run}';

    protected $description = 'Mail users when their certifications or assigned assets are expired or within the configured warning window.';

    public function handle(CertificationExpiryNotificationService $certifications, AssetExpiryNotificationService $assets): int
    {
        $result = [
            'certifications' => $certifications->sendDueNotifications((bool) $this->option('dry-run')),
            'assets' => $assets->sendDueNotifications((bool) $this->option('dry-run')),
        ];

        $this->info(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ($result['certifications']['failed'] + $result['assets']['failed']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
