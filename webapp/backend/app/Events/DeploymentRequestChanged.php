<?php

namespace App\Events;

use App\Models\DeploymentRequest;
use App\Models\User;
use App\Support\ApiDateTime;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DeploymentRequestChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly DeploymentRequest $deploymentRequest,
        public readonly User $actor,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('deployment-requests')];
        if ($this->deploymentRequest->deployment_id === null) {
            return $channels;
        }

        return [
            ...$channels,
            new PrivateChannel('operations'),
            new PrivateChannel('deployments.'.$this->deploymentRequest->deployment_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deployment-request.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'deployment_request_id' => $this->deploymentRequest->id,
            'deployment_id' => $this->deploymentRequest->deployment_id,
            'reference' => $this->deploymentRequest->deployment?->reference,
            'updated_at' => ApiDateTime::dateTime($this->deploymentRequest->updated_at),
        ];
    }
}
