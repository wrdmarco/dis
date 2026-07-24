<?php

namespace App\Services;

use App\Contracts\DispatchNotificationQueue;
use App\Jobs\SendFcmNotification;
use App\Models\DispatchPushOutbox;
use Illuminate\Contracts\Bus\Dispatcher;

final class QueuedDispatchNotificationQueue implements DispatchNotificationQueue
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function enqueue(DispatchPushOutbox $notification): void
    {
        $data = $notification->data;

        $job = (new SendFcmNotification(
            (string) $notification->fcm_token_id,
            (string) $notification->message_type,
            (string) $notification->title,
            (string) $notification->body,
            is_array($data) ? $data : [],
            (string) $notification->dispatch_request_id,
            (string) $notification->id,
        ))->onConnection('push')->onQueue('push')->beforeCommit();

        // The outbox service commits a short claim before calling this method.
        // beforeCommit deliberately overrides Redis' global after_commit flag:
        // there is no open database transaction here, and a worker must be
        // allowed to consume the already durable outbox lease immediately.
        $this->dispatcher->dispatch($job);
    }
}
