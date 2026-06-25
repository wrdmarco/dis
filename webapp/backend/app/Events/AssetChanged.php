<?php

namespace App\Events;

use App\Models\Asset;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AssetChanged implements ShouldBroadcastAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Asset $asset, public readonly string $action) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('operations')];
    }

    public function broadcastAs(): string
    {
        return 'asset.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->asset->id,
            'asset_tag' => $this->asset->asset_tag,
            'name' => $this->asset->name,
            'type' => $this->asset->type,
            'status' => $this->asset->status,
            'action' => $this->action,
        ];
    }
}

