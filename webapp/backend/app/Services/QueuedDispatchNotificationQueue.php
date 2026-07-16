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
        ))->onQueue('push')->beforeCommit();

        // The outbox row itself was committed with the dispatch already. This
        // enqueue must happen inside the flush transaction so a definite
        // connection failure is caught before queued_at is persisted. A crash
        // after Redis accepts the job but before the database acknowledgement
        // can enqueue it again: this is an explicit at-least-once guarantee.
        // The stable dispatch collapse id reduces visible duplicates that are
        // still pending at FCM/APNs; it cannot provide exactly-once delivery.
        // beforeCommit deliberately overrides Redis' global after_commit flag.
        $this->dispatcher->dispatch($job);
    }
}
