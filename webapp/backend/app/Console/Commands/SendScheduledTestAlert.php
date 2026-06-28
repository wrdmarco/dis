<?php

namespace App\Console\Commands;

use App\Services\TestAlertService;
use Illuminate\Console\Command;

final class SendScheduledTestAlert extends Command
{
    protected $signature = 'dis:send-scheduled-test-alert';

    protected $description = 'Send the configured scheduled DIS test alert.';

    public function handle(TestAlertService $service): int
    {
        $result = $service->sendScheduled();

        $this->info('Scheduled test alert checked. Sent: '.$result['sent_users'].', skipped: '.$result['skipped_users'].'.');

        return self::SUCCESS;
    }
}
