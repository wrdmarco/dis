<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class DeploymentRequestDeleted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $deploymentRequestId,
        public readonly string $deletedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('deployment-requests')];
    }

    public function broadcastAs(): string
    {
        return 'deployment-request.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'deployment_request_id' => $this->deploymentRequestId,
            'deployment_id' => null,
            'reference' => null,
            'updated_at' => $this->deletedAt,
            'action' => 'deleted',
            'deleted' => true,
        ];
    }
}
