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

    /**
     * @var array{id: string, asset_tag: string|null, name: string, type: string, status: string, action: string}
     */
    private readonly array $payload;

    public function __construct(Asset $asset, public readonly string $action)
    {
        $this->payload = [
            'id' => (string) $asset->id,
            'asset_tag' => $asset->asset_tag,
            'name' => $asset->name,
            'type' => $asset->type,
            'status' => $asset->status,
            'action' => $action,
        ];
    }

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
        return $this->payload;
    }
}
