<?php

namespace App\Repositories;

use App\Contracts\QueueTransportMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class LaravelQueueTransportMetrics implements QueueTransportMetrics
{
    public function pendingCount(string $connection, string $queue): ?int
    {
        try {
            return Queue::connection($connection)->size($queue);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function failedCount(string $queue): ?int
    {
        try {
            return DB::table('failed_jobs')->where('queue', $queue)->count();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
