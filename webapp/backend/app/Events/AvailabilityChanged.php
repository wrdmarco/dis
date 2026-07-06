<?php

namespace App\Events;

use App\Models\AvailabilityStatus;
use App\Support\ApiDateTime;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AvailabilityChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly AvailabilityStatus $status) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('operations'),
            new PrivateChannel('users.'.$this->status->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'availability.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->status->id,
            'user_id' => $this->status->user_id,
            'status' => $this->status->status,
            'is_available' => $this->status->is_available,
            'effective_at' => ApiDateTime::dateTime($this->status->effective_at),
        ];
    }
}
