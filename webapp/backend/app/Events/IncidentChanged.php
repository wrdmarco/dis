<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class IncidentChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Incident $incident, public readonly string $action) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('operations'),
            new PrivateChannel('incidents.'.$this->incident->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'incident.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->incident->id,
            'reference' => $this->incident->reference,
            'title' => $this->incident->title,
            'priority' => $this->incident->priority,
            'status' => $this->incident->status,
            'action' => $this->action,
            'updated_at' => $this->incident->updated_at?->toIso8601String(),
        ];
    }
}
