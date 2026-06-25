<?php

namespace App\Events;

use App\Models\LocationUpdate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class LocationUpdated implements ShouldBroadcastAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly LocationUpdate $locationUpdate) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('operations'),
            new PrivateChannel('incidents.'.$this->locationUpdate->incident_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->locationUpdate->user_id,
            'latitude' => $this->locationUpdate->latitude,
            'longitude' => $this->locationUpdate->longitude,
            'accuracy_meters' => $this->locationUpdate->accuracy_meters,
            'recorded_at' => $this->locationUpdate->recorded_at?->toIso8601String(),
        ];
    }
}
