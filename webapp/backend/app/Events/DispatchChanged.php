<?php

namespace App\Events;

use App\Models\DispatchRequest;
use App\Support\ApiDateTime;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DispatchChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly DispatchRequest $dispatch, public readonly string $action) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('operations'),
            new PrivateChannel('incidents.'.$this->dispatch->incident_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dispatch.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->dispatch->id,
            'incident_id' => $this->dispatch->incident_id,
            'status' => $this->dispatch->status,
            'priority' => $this->dispatch->priority,
            'action' => $this->action,
            'sent_at' => ApiDateTime::dateTime($this->dispatch->sent_at),
        ];
    }
}
