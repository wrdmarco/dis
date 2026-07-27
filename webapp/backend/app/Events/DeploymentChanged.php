<?php

namespace App\Events;

use App\Models\Deployment;
use App\Support\ApiDateTime;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DeploymentChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Deployment $deployment, public readonly string $action) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('operations'),
            new PrivateChannel('deployments.'.$this->deployment->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deployment.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->deployment->id,
            'reference' => $this->deployment->reference,
            'title' => $this->deployment->title,
            'priority' => $this->deployment->priority,
            'status' => $this->deployment->status,
            'action' => $this->action,
            'updated_at' => ApiDateTime::dateTime($this->deployment->updated_at),
        ];
    }
}
