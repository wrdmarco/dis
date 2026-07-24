<?php

namespace App\Contracts;

interface QueueTransportMetrics
{
    public function pendingCount(string $connection, string $queue): ?int;

    public function failedCount(string $queue): ?int;
}
