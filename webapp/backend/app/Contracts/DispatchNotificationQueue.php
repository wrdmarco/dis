<?php

namespace App\Contracts;

use App\Models\DispatchPushOutbox;

interface DispatchNotificationQueue
{
    public function enqueue(DispatchPushOutbox $notification): void;
}
