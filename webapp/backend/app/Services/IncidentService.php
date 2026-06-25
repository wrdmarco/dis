<?php

namespace App\Services;

use App\Events\IncidentChanged;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final class IncidentService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly DispatchService $dispatchService,
        private readonly LocationService $locationService,
        private readonly StatusService $statusService,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): Incident
    {
        return DB::transaction(function () use ($data, $actor): Incident {
            $incident = Incident::query()->create($data + [
                'reference' => $this->nextReference(),
                'created_by' => $actor->id,
                'status' => $data['status'] ?? 'draft',
                'opened_at' => now(),
            ]);

            $incident->statusHistory()->create([
                'from_status' => null,
                'to_status' => $incident->status,
                'changed_by' => $actor->id,
                'reason' => 'Incident created.',
                'created_at' => now(),
            ]);

            $this->auditService->record('incidents.created', $incident, $actor);
            $this->broadcastIncidentChange($incident, 'created');

            return $incident->load(['coordinator', 'team', 'statusHistory']);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Incident $incident, array $data, User $actor): Incident
    {
        return DB::transaction(function () use ($incident, $data, $actor): Incident {
            $beforeStatus = $incident->status;
            $statusReason = $data['status_reason'] ?? null;
            unset($data['status_reason']);

            $incident->update($data);

            if (array_key_exists('status', $data) && $data['status'] !== $beforeStatus) {
                $incident->statusHistory()->create([
                    'from_status' => $beforeStatus,
                    'to_status' => $data['status'],
                    'changed_by' => $actor->id,
                    'reason' => $statusReason,
                    'created_at' => now(),
                ]);
            }

            if ($beforeStatus === 'draft' && ($data['status'] ?? null) === 'active') {
                $this->dispatchService->createAndSendForIncidentActivation($incident->refresh(), $actor, $statusReason);
            }

            if (array_key_exists('status', $data) && $data['status'] !== $beforeStatus && in_array($data['status'], ['resolved', 'cancelled'], true)) {
                $this->locationService->stopForIncident($incident->refresh(), $actor);
                $this->resetAcceptedRecipientsToAvailable($incident->refresh(), $actor, $data['status']);
            }

            $this->auditService->record('incidents.updated', $incident, $actor);
            $this->broadcastIncidentChange($incident->refresh(), 'updated');

            return $incident->load(['coordinator', 'team', 'statusHistory']);
        });
    }

    public function close(Incident $incident, User $actor, ?string $reason): Incident
    {
        return $this->update($incident, ['status' => 'resolved', 'closed_at' => now(), 'status_reason' => $reason], $actor);
    }

    public function cancel(Incident $incident, User $actor, ?string $reason): Incident
    {
        return $this->update($incident, ['status' => 'cancelled', 'closed_at' => now(), 'status_reason' => $reason], $actor);
    }

    private function nextReference(): string
    {
        return 'DIS-'.now()->format('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    private function resetAcceptedRecipientsToAvailable(Incident $incident, User $actor, string $terminalStatus): void
    {
        $incident->load([
            'dispatchRequests.recipients.user',
        ]);

        $reason = $terminalStatus === 'resolved'
            ? 'Incident afgerond; gebruiker automatisch weer beschikbaar gezet.'
            : 'Incident geannuleerd; gebruiker automatisch weer beschikbaar gezet.';

        $incident->dispatchRequests
            ->flatMap(fn ($dispatch) => $dispatch->recipients)
            ->filter(fn ($recipient): bool => $recipient->response_status === 'accepted'
                && $recipient->user !== null
                && (bool) $recipient->user->push_enabled)
            ->unique('user_id')
            ->each(fn ($recipient) => $this->statusService->setStatus($recipient->user, 'available', $actor, $reason, true));
    }

    private function broadcastIncidentChange(Incident $incident, string $action): void
    {
        try {
            IncidentChanged::dispatch($incident, $action);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
