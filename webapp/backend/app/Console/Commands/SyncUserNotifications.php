<?php

namespace App\Console\Commands;

use App\Services\UserNotificationService;
use Illuminate\Console\Command;

final class SyncUserNotifications extends Command
{
    protected $signature = 'dis:sync-user-notifications';

    protected $description = 'Synchronize personal certification and assigned-asset reminders.';

    public function handle(UserNotificationService $notifications): int
    {
        $this->info(json_encode(
            $notifications->syncDueReminders(),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
