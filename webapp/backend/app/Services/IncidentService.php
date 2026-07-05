<?php

namespace App\Services;

use App\Events\IncidentChanged;
use App\Jobs\GenerateIncidentReport;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IncidentService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly DroneFlightContextService $droneFlightContextService,
        private readonly DispatchService $dispatchService,
        private readonly GeocodingService $geocodingService,
        private readonly IncidentFormService $incidentFormService,
        private readonly IncidentReportService $incidentReportService,
        private readonly LocationService $locationService,
        private readonly StatusService $statusService,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): Incident
    {
        return DB::transaction(function () use ($data, $actor): Incident {
            $data = $this->resolveLocationCoordinates($data);
            $data['custom_fields'] = $this->incidentFormService->normalizeCustomValues($data);
            $teamIds = $this->teamIdsFromPayload($data);
            unset($data['team_ids']);
            $data['team_id'] = $teamIds[0] ?? null;

            $incident = Incident::query()->create($data + [
                'reference' => $this->nextReference(),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
                'created_by_email' => $actor->email,
                'coordinator_name' => $this->snapshotUserName($data['coordinator_id'] ?? null),
                'coordinator_email' => $this->snapshotUserEmail($data['coordinator_id'] ?? null),
                'status' => $data['status'] ?? 'draft',
                'opened_at' => now(),
            ]);
            $incident->teams()->sync($teamIds);
            $this->refreshDroneFlightContextWhenLocated($incident);

            $incident->statusHistory()->create([
                'from_status' => null,
                'to_status' => $incident->status,
                'changed_by' => $actor->id,
                'changed_by_name' => $actor->name,
                'changed_by_email' => $actor->email,
                'reason' => 'Incident created.',
                'created_at' => now(),
            ]);

            $this->auditService->record('incidents.created', $incident, $actor);
            $this->broadcastIncidentChange($incident, 'created');

            return $incident->load(['coordinator', 'team', 'teams', 'statusHistory']);
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
            $directDispatch = (bool) ($data['direct_dispatch'] ?? false);
            $dispatchOptions = $this->dispatchOptionsFromPayload($data);
            $data = $this->resolveLocationCoordinates($data, $incident);
            if (array_key_exists('custom_fields', $data)) {
                $data['custom_fields'] = $this->incidentFormService->normalizeCustomValues($data);
            }
            $teamIds = array_key_exists('team_ids', $data) ? $this->teamIdsFromPayload($data) : null;
            unset($data['status_reason']);
            unset($data['direct_dispatch']);
            unset($data['dispatch_recipient_count']);
            unset($data['team_ids']);
            if (is_array($teamIds)) {
                $data['team_id'] = $teamIds[0] ?? null;
            }

            if (array_key_exists('status', $data)) {
                if ($incident->status === 'draft' && $data['status'] === 'dispatching' && ! $directDispatch) {
                    throw ValidationException::withMessages(['status' => ['Activeer het concept voordat de alarmering wordt verstuurd.']]);
                }

                $data = $this->applyStatusTimestamps($incident, $data);
            }

            if (array_key_exists('coordinator_id', $data)) {
                $data['coordinator_name'] = $this->snapshotUserName($data['coordinator_id']);
                $data['coordinator_email'] = $this->snapshotUserEmail($data['coordinator_id']);
            }

            $incident->update($data);
            if (is_array($teamIds)) {
                $incident->teams()->sync($teamIds);
            }
            $this->refreshDroneFlightContextWhenLocationChanged($incident, $data);

            if (array_key_exists('status', $data) && $data['status'] !== $beforeStatus) {
                $incident->statusHistory()->create([
                    'from_status' => $beforeStatus,
                    'to_status' => $data['status'],
                    'changed_by' => $actor->id,
                    'changed_by_name' => $actor->name,
                    'changed_by_email' => $actor->email,
                    'reason' => $statusReason,
                    'created_at' => now(),
                ]);
            }

            if ($beforeStatus !== 'active' && ($data['status'] ?? null) === 'active') {
                $this->dispatchService->sendPreannouncementForIncidentActivation($incident->refresh(), $actor, $statusReason, $dispatchOptions);
            }

            if (($beforeStatus === 'active' || ($beforeStatus === 'draft' && $directDispatch)) && ($data['status'] ?? null) === 'dispatching') {
                $this->dispatchService->createAndSendForIncidentActivation($incident->refresh(), $actor, $statusReason, $dispatchOptions);
            }

            if ($beforeStatus === 'active' && ($data['status'] ?? null) === 'cancelled') {
                $this->dispatchService->sendCancellationForActiveIncident($incident->refresh(), $actor);
            }

            if (array_key_exists('status', $data) && $data['status'] !== $beforeStatus && in_array($data['status'], ['resolved', 'cancelled'], true)) {
                $this->locationService->stopForIncident($incident->refresh(), $actor);
                $this->resetAcceptedRecipientsToAvailable($incident->refresh(), $actor, $data['status']);
                DB::afterCommit(fn () => GenerateIncidentReport::dispatch((string) $incident->getKey()));
            }

            $this->auditService->record('incidents.updated', $incident, $actor);
            $this->broadcastIncidentChange($incident->refresh(), 'updated');

            return $incident->load(['coordinator', 'team', 'teams', 'statusHistory']);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function dispatchOptionsFromPayload(array $data): array
    {
        $options = [];
        if (array_key_exists('dispatch_recipient_count', $data) && $data['dispatch_recipient_count'] !== null && $data['dispatch_recipient_count'] !== '') {
            $options['dispatch_recipient_count'] = (int) $data['dispatch_recipient_count'];
        }

        return $options;
    }

    public function close(Incident $incident, User $actor, ?string $reason): Incident
    {
        return $this->update($incident, ['status' => 'resolved', 'closed_at' => now(), 'status_reason' => $reason], $actor);
    }

    public function cancel(Incident $incident, User $actor, ?string $reason): Incident
    {
        return $this->update($incident, ['status' => 'cancelled', 'closed_at' => now(), 'status_reason' => $reason], $actor);
    }

    public function delete(Incident $incident, User $actor): void
    {
        DB::transaction(function () use ($incident, $actor): void {
            $incidentId = (string) $incident->getKey();

            $this->auditService->record('incidents.deleted', $incident, $actor, [
                'reference' => $incident->reference,
                'title' => $incident->title,
                'status' => $incident->status,
                'deleted_related_data' => true,
            ]);

            $this->locationService->stopForIncident($incident, $actor);
            $this->broadcastIncidentChange($incident, 'deleted');
            $incident->forceDelete();

            DB::afterCommit(fn () => Storage::disk('local')->deleteDirectory('incident-reports/'.$incidentId));
        });
    }

    private function nextReference(): string
    {
        return 'DIS-'.now()->format('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    private function snapshotUserName(mixed $userId): ?string
    {
        return is_string($userId) && $userId !== ''
            ? User::query()->whereKey($userId)->value('name')
            : null;
    }

    private function snapshotUserEmail(mixed $userId): ?string
    {
        return is_string($userId) && $userId !== ''
            ? User::query()->whereKey($userId)->value('email')
            : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function teamIdsFromPayload(array $data): array
    {
        $teamIds = $data['team_ids'] ?? [];
        if (! is_array($teamIds)) {
            $teamIds = [];
        }

        if (($data['team_id'] ?? null) !== null && $data['team_id'] !== '') {
            array_unshift($teamIds, (string) $data['team_id']);
        }

        return array_values(array_unique(array_filter($teamIds, fn (mixed $teamId): bool => is_string($teamId) && $teamId !== '')));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyStatusTimestamps(Incident $incident, array $data): array
    {
        $nextStatus = $data['status'] ?? null;

        if (in_array($nextStatus, ['resolved', 'cancelled'], true) && $incident->closed_at === null) {
            $data['closed_at'] = now();
        }

        if (! in_array($nextStatus, ['resolved', 'cancelled'], true) && in_array($incident->status, ['resolved', 'cancelled'], true)) {
            $data['closed_at'] = null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function refreshDroneFlightContextWhenLocationChanged(Incident $incident, array $data): void
    {
        if (
            ! array_key_exists('latitude', $data)
            && ! array_key_exists('longitude', $data)
            && ! array_key_exists('location_label', $data)
        ) {
            return;
        }

        $this->refreshDroneFlightContextWhenLocated($incident);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveLocationCoordinates(array $data, ?Incident $incident = null): array
    {
        if (! array_key_exists('location_label', $data)) {
            return $data;
        }

        if ($this->hasCoordinatePair($data['latitude'] ?? null, $data['longitude'] ?? null)) {
            return $data;
        }

        $locationLabel = trim((string) ($data['location_label'] ?? ''));
        if ($locationLabel === '') {
            $data['latitude'] = null;
            $data['longitude'] = null;

            return $data;
        }

        $coordinates = $this->geocodingService->coordinatesFor($locationLabel);
        if ($coordinates !== null) {
            $data['latitude'] = $coordinates['latitude'];
            $data['longitude'] = $coordinates['longitude'];

            return $data;
        }

        if ($incident !== null && trim((string) $incident->location_label) !== $locationLabel) {
            $data['latitude'] = null;
            $data['longitude'] = null;
        }

        return $data;
    }

    private function hasCoordinatePair(mixed $latitude, mixed $longitude): bool
    {
        return $this->validCoordinate($latitude, -90, 90) && $this->validCoordinate($longitude, -180, 180);
    }

    private function validCoordinate(mixed $value, float $minimum, float $maximum): bool
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return false;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) && $coordinate >= $minimum && $coordinate <= $maximum;
    }

    private function refreshDroneFlightContextWhenLocated(Incident $incident): void
    {
        try {
            $context = $this->droneFlightContextService->previewForIncident($incident);
        } catch (Throwable $exception) {
            report($exception);
            $context = [
                'generated_at' => now()->toIso8601String(),
                'location' => [
                    'label' => $incident->location_label,
                    'latitude' => $incident->latitude,
                    'longitude' => $incident->longitude,
                ],
                'airspace' => [
                    'provider' => 'Aeret Drone PreFlight',
                    'status' => 'unavailable',
                    'summary' => 'Drone vluchtcheck kon niet worden opgehaald. Controleer Aeret handmatig.',
                    'no_fly_zones' => [],
                    'notams' => [],
                    'restrictions' => [],
                    'errors' => [$exception->getMessage()],
                ],
                'weather' => [
                    'provider' => 'Open-Meteo',
                    'status' => 'unavailable',
                    'summary' => 'Weerdata kon niet worden opgehaald.',
                    'errors' => [$exception->getMessage()],
                ],
                'checklist' => [],
            ];
        }

        $incident->forceFill(['drone_flight_context' => $context])->save();
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
            ->whereIn('status', ['sent', 'escalated'])
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
