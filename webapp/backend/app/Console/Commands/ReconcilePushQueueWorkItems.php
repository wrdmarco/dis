<?php

namespace App\Console\Commands;

use App\Repositories\PushQueueWorkItemRepository;
use App\Services\PushQueueLifecyclePolicy;
use Illuminate\Console\Command;

final class ReconcilePushQueueWorkItems extends Command
{
    protected $signature = 'dis:reconcile-push-queue-work-items';

    protected $description = 'Flag safely bounded stale push queue monitor rows without changing delivery state.';

    public function handle(
        PushQueueWorkItemRepository $workItems,
        PushQueueLifecyclePolicy $policy,
    ): int {
        $staleAfterSeconds = $policy->staleActiveAfterSeconds();
        $reconciled = $workItems->reconcileStaleActive($staleAfterSeconds);

        $this->info(json_encode([
            'reconciled' => $reconciled,
            'stale_after_seconds' => $staleAfterSeconds,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
