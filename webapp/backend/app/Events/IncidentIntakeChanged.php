<?php

namespace App\Events;

use App\Models\IncidentIntakeDossier;
use App\Models\User;
use App\Support\ApiDateTime;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class IncidentIntakeChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly IncidentIntakeDossier $dossier,
        public readonly User $actor,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('intakes')];
        if ($this->dossier->incident_id === null) {
            return $channels;
        }

        return [
            ...$channels,
            new PrivateChannel('operations'),
            new PrivateChannel('incidents.'.$this->dossier->incident_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'incident.intake.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->dossier->incident_id,
            'reference' => $this->dossier->incident?->reference,
            'updated_at' => ApiDateTime::dateTime($this->dossier->updated_at),
        ];
    }
}
