<?php

namespace App\Services;

use App\Events\DeploymentChanged;
use App\Jobs\GenerateDeploymentReport;
use App\Models\Deployment;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiDateTime;
use App\Support\PhoneNumber;
use App\Support\ProfileLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeploymentService
{
    /** @var list<string> */
    private const STATUSES = ['draft', 'active', 'dispatching', 'in_progress', 'resolved', 'cancelled'];

    /** @var array<string, list<string>> */
    private const NORMAL_STATUS_TRANSITIONS = [
        'draft' => ['active', 'cancelled'],
        'active' => ['dispatching', 'cancelled'],
        'dispatching' => ['in_progress'],
        'in_progress' => ['resolved'],
        'resolved' => [],
        'cancelled' => [],
    ];

    /** @var list<string> */
    private array $lastDispatchWarnings = [];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly DroneFlightContextService $droneFlightContextService,
        private readonly DispatchService $dispatchService,
        private readonly GeocodingService $geocodingService,
        private readonly DeploymentFormService $deploymentFormService,
        private readonly LocationService $locationService,
        private readonly StatusService $statusService,
        private readonly AvailabilityScheduleResolver $availabilityScheduleResolver,
        private readonly DeploymentReferenceService $deploymentReferenceService,
        private readonly DeploymentReportService $deploymentReportService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Deployment
    {
        return DB::transaction(function () use ($data, $actor): Deployment {
            $data = $this->withoutClientControlledReferenceFields($data);
            $data = $this->resolveLocationCoordinates($data);
            $phoneCountry = $this->phoneCountryFromDeploymentData($data);
            $data = $this->normalizeDeploymentPhoneFields($data, $phoneCountry);
            $data['custom_fields'] = $this->deploymentFormService->normalizeCustomValues($data, $phoneCountry);
            $teamIds = $this->teamIdsFromPayload($data);
            unset($data['team_ids']);
            $data['team_id'] = $teamIds[0] ?? null;
            $data['status'] = 'draft';
            $data['closed_at'] = null;
            $reference = $this->deploymentReferenceService->nextReference(
                (bool) ($data['is_test'] ?? false),
            );

            $deployment = new Deployment($data + [
                'reference' => $reference['reference'],
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
                'created_by_email' => $actor->email,
                'coordinator_name' => $this->snapshotUserName($data['coordinator_id'] ?? null),
                'coordinator_email' => $this->snapshotUserEmail($data['coordinator_id'] ?? null),
                'opened_at' => now(),
            ]);
            $deployment->forceFill([
                'reference_sequence' => $reference['sequence'],
            ])->save();
            $deployment->teams()->sync($teamIds);
            $this->refreshDroneFlightContextWhenLocated($deployment);

            $deployment->statusHistory()->create([
                'from_status' => null,
                'to_status' => $deployment->status,
                'changed_by' => $actor->id,
                'changed_by_name' => $actor->name,
                'changed_by_email' => $actor->email,
                'reason' => 'Inzet aangemaakt.',
                'created_at' => now(),
            ]);

            $this->auditService->record('deployments.created', $deployment, $actor);
            $this->broadcastDeploymentChange($deployment, 'created');

            return $deployment->load(['coordinator', 'team', 'teams', 'statusHistory']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Deployment $deployment, array $data, User $actor): Deployment
    {
        $data = $this->withoutClientControlledReferenceFields($data);
        $this->lastDispatchWarnings = [];
        $requestedStatus = isset($data['status']) ? (string) $data['status'] : null;
        $manualStatusOverride = filter_var(
            $data['manual_status_override'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        [$updatedDeployment, $statusChanged] = DB::transaction(function () use ($deployment, $data, $actor, $manualStatusOverride): array {
            $deployment = Deployment::query()
                ->lockForUpdate()
                ->findOrFail($deployment->getKey());
            $beforeStatus = $deployment->status;
            $statusReason = $data['status_reason'] ?? null;
            $directDispatch = (bool) ($data['direct_dispatch'] ?? false);
            $this->validateStatusTransition(
                $deployment,
                isset($data['status']) ? (string) $data['status'] : null,
                $directDispatch,
                $manualStatusOverride,
                $actor,
                $statusReason,
            );
            $dispatchOptions = $this->dispatchOptionsFromPayload($data);
            $data = $this->resolveLocationCoordinates($data, $deployment);
            $phoneCountry = $this->phoneCountryFromDeploymentData($data, $deployment);
            $data = $this->normalizeDeploymentPhoneFields($data, $phoneCountry);
            if (array_key_exists('custom_fields', $data)) {
                $customFieldPatch = $this->deploymentFormService->normalizeCustomValues($data, $phoneCountry);
                $customFields = is_array($deployment->custom_fields) ? $deployment->custom_fields : [];
                foreach ($customFieldPatch as $key => $value) {
                    if ($value === null) {
                        unset($customFields[$key]);
                    } else {
                        $customFields[$key] = $value;
                    }
                }
                $data['custom_fields'] = $customFields;
            }
            $teamIds = array_key_exists('team_ids', $data) ? $this->teamIdsFromPayload($data) : null;
            unset($data['status_reason']);
            unset($data['direct_dispatch']);
            unset($data['manual_status_override']);
            unset($data['dispatch_recipient_count']);
            unset($data['team_ids']);
            if (is_array($teamIds)) {
                $data['team_id'] = $teamIds[0] ?? null;
            }

            if (array_key_exists('status', $data)) {
                $data = $this->applyStatusTimestamps($deployment, $data);
            }
            if ($manualStatusOverride && array_key_exists('status', $data) && $data['status'] !== $beforeStatus) {
                $data['report_pdf_path'] = null;
                $data['report_generated_at'] = null;
                $data['report_finalized_at'] = null;
                $data['report_generation_error'] = null;
            }

            if (array_key_exists('coordinator_id', $data)) {
                $data['coordinator_name'] = $this->snapshotUserName($data['coordinator_id']);
                $data['coordinator_email'] = $this->snapshotUserEmail($data['coordinator_id']);
            }

            $coordinatesChanged = $this->coordinatesChanged($deployment, $data);
            if ($coordinatesChanged) {
                $deployment->forceFill([
                    'province_code' => null,
                    'province_name' => null,
                    'province_source' => null,
                    'province_resolved_at' => null,
                    'country_code' => null,
                    'country_name' => null,
                    'country_source' => null,
                    'country_resolved_at' => null,
                    'location_enrichment_attempted_at' => null,
                ]);
            }
            $deployment->fill($data)->save();
            if (is_array($teamIds)) {
                $deployment->teams()->sync($teamIds);
            }
            $this->refreshDroneFlightContextWhenLocationChanged($deployment, $data);

            if (array_key_exists('status', $data) && $data['status'] !== $beforeStatus) {
                $deployment->statusHistory()->create([
                    'from_status' => $beforeStatus,
                    'to_status' => $data['status'],
                    'changed_by' => $actor->id,
                    'changed_by_name' => $actor->name,
                    'changed_by_email' => $actor->email,
                    'reason' => $statusReason,
                    'created_at' => now(),
                ]);
            }

            if (! $manualStatusOverride && $beforeStatus !== 'active' && ($data['status'] ?? null) === 'active') {
                $result = $this->dispatchService->sendPreannouncementForDeploymentActivation($deployment->refresh(), $actor, $statusReason, $dispatchOptions);
                $this->lastDispatchWarnings = $result['warnings'];
            }

            if (! $manualStatusOverride && ($beforeStatus === 'active' || ($beforeStatus === 'draft' && $directDispatch)) && ($data['status'] ?? null) === 'dispatching') {
                $result = $this->dispatchService->createAndSendForDeploymentActivation($deployment->refresh(), $actor, $statusReason, $dispatchOptions);
                $this->lastDispatchWarnings = $result['warnings'];
            }

            if (! $manualStatusOverride && $beforeStatus === 'active' && ($data['status'] ?? null) === 'cancelled') {
                $this->dispatchService->sendCancellationForActiveDeployment($deployment->refresh(), $actor);
            }

            if (array_key_exists('status', $data) && $data['status'] !== $beforeStatus && in_array($data['status'], ['resolved', 'cancelled'], true)) {
                $this->locationService->stopForDeployment($deployment->refresh(), $actor);
                $this->resetAcceptedRecipientsToScheduledAvailability($deployment->refresh(), $actor, $data['status']);
                DB::afterCommit(fn () => GenerateDeploymentReport::dispatch((string) $deployment->getKey()));
            }

            $this->auditService->record('deployments.updated', $deployment, $actor);
            $this->broadcastDeploymentChange($deployment->refresh(), 'updated');

            return [
                $deployment->load(['coordinator', 'team', 'teams', 'statusHistory']),
                (string) $beforeStatus !== (string) $deployment->status,
            ];
        });

        if (! $manualStatusOverride && $statusChanged && in_array($requestedStatus, ['active', 'dispatching'], true)) {
            // This call is intentionally outside the outer deployment
            // transaction. It is an idempotent safety net for runtimes where a
            // nested after-commit callback or scheduler is delayed: the first
            // preannouncement/alarm is queued before the HTTP request returns.
            $this->dispatchService->flushPushOutboxForDeployment($updatedDeployment);
        }

        return $updatedDeployment;
    }

    public function invalidateDraftDispatchesAfterDeploymentRequestChange(Deployment $deployment, User $actor): void
    {
        $this->dispatchService->invalidateDraftsAfterDeploymentRequestChange($deployment, $actor);
    }

    private function validateStatusTransition(
        Deployment $deployment,
        ?string $nextStatus,
        bool $directDispatch,
        bool $manualStatusOverride,
        User $actor,
        mixed $statusReason,
    ): void {
        if ($manualStatusOverride && ! $actor->hasRole(Role::SYSTEM_ADMINISTRATOR)) {
            throw ValidationException::withMessages([
                'manual_status_override' => ['Alleen een systeembeheerder mag de inzetstatus handmatig corrigeren.'],
            ]);
        }

        if ($nextStatus === null) {
            if ($manualStatusOverride) {
                throw ValidationException::withMessages([
                    'status' => ['Kies een status voor de handmatige correctie.'],
                ]);
            }

            return;
        }

        if (! in_array($nextStatus, self::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['De gekozen inzetstatus is ongeldig.'],
            ]);
        }

        if ($nextStatus === (string) $deployment->status) {
            return;
        }

        if (in_array($nextStatus, ['active', 'dispatching'], true) && ! $deployment->deployment_request_decision_valid) {
            throw ValidationException::withMessages([
                'status' => ['Beoordeel de bijgewerkte uitvraag opnieuw voordat je de inzet activeert of alarmeert.'],
            ]);
        }

        if ($manualStatusOverride) {
            if (trim((string) $statusReason) === '') {
                throw ValidationException::withMessages([
                    'status_reason' => ['Leg de reden van de handmatige statuscorrectie vast.'],
                ]);
            }

            return;
        }

        // Test alerts retain their isolated lifecycle. Operational deployment
        // transitions are constrained by the matrix below.
        if ($deployment->is_test) {
            return;
        }

        if ((string) $deployment->status === 'draft' && $nextStatus === 'dispatching') {
            if ($directDispatch) {
                return;
            }

            throw ValidationException::withMessages([
                'status' => ['Activeer het concept voordat de alarmering wordt verstuurd, of gebruik Direct alarmeren.'],
            ]);
        }

        if (! in_array($nextStatus, self::NORMAL_STATUS_TRANSITIONS[(string) $deployment->status] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ['Deze overgang van inzetstatus is niet toegestaan.'],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function lastDispatchWarnings(): array
    {
        return $this->lastDispatchWarnings;
    }

    /**
     * @param  array<string, mixed>  $data
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

    public function close(Deployment $deployment, User $actor, ?string $reason): Deployment
    {
        return $this->update($deployment, ['status' => 'resolved', 'closed_at' => now(), 'status_reason' => $reason], $actor);
    }

    public function cancel(Deployment $deployment, User $actor, ?string $reason): Deployment
    {
        return $this->update($deployment, ['status' => 'cancelled', 'closed_at' => now(), 'status_reason' => $reason], $actor);
    }

    /**
     * @return array{internal_notes: string|null, updated_at: string|null}
     */
    public function internalNotes(Deployment $deployment): array
    {
        return [
            'internal_notes' => $deployment->internal_notes,
            'updated_at' => ApiDateTime::dateTime($deployment->updated_at),
        ];
    }

    /**
     * @return array{internal_notes: string|null, updated_at: string|null}
     */
    public function updateInternalNotes(Deployment $deployment, User $actor, ?string $notes): array
    {
        if (in_array($deployment->status, ['resolved', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'internal_notes' => ['Kladblokregels kunnen alleen tijdens een actieve inzet worden aangepast.'],
            ]);
        }

        $notes = trim((string) $notes);
        if ($notes === '') {
            throw ValidationException::withMessages([
                'internal_notes' => ['Vul eerst een kladblokregel in.'],
            ]);
        }

        $this->auditService->record('deployments.internal_note_added', $deployment, $actor, [
            'reference' => $deployment->reference,
            'visible_to_app_users' => false,
        ], $notes);

        $deployment->forceFill(['internal_notes' => null])->save();
        $this->broadcastDeploymentChange($deployment->refresh(), 'internal_notes_updated');

        return $this->internalNotes($deployment);
    }

    public function delete(Deployment $deployment, User $actor): void
    {
        DB::transaction(function () use ($deployment, $actor): void {
            $deploymentId = (string) $deployment->getKey();

            $this->auditService->record('deployments.deleted', $deployment, $actor, [
                'reference' => $deployment->reference,
                'title' => $deployment->title,
                'status' => $deployment->status,
                'deleted_related_data' => true,
            ]);

            $this->locationService->stopForDeployment($deployment, $actor);
            if (! in_array($deployment->status, ['resolved', 'cancelled'], true)) {
                $this->resetAcceptedRecipientsToScheduledAvailability($deployment, $actor, 'deleted');
            }
            $this->broadcastDeploymentChange($deployment, 'deleted');
            $deployment->forceDelete();

            DB::afterCommit(function () use ($deploymentId): void {
                $this->deploymentReportService->deleteStoredReportDirectories($deploymentId);
            });
        });
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
     * @param  array<string, mixed>  $data
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyStatusTimestamps(Deployment $deployment, array $data): array
    {
        $nextStatus = $data['status'] ?? null;

        if (in_array($nextStatus, ['resolved', 'cancelled'], true) && $deployment->closed_at === null) {
            $data['closed_at'] = now();
        }

        if (! in_array($nextStatus, ['resolved', 'cancelled'], true) && in_array($deployment->status, ['resolved', 'cancelled'], true)) {
            $data['closed_at'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function refreshDroneFlightContextWhenLocationChanged(Deployment $deployment, array $data): void
    {
        if (
            ! array_key_exists('latitude', $data)
            && ! array_key_exists('longitude', $data)
            && ! array_key_exists('location_label', $data)
        ) {
            return;
        }

        $this->refreshDroneFlightContextWhenLocated($deployment);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveLocationCoordinates(array $data, ?Deployment $deployment = null): array
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

        if ($deployment !== null && trim((string) $deployment->location_label) !== $locationLabel) {
            $data['latitude'] = null;
            $data['longitude'] = null;
        }

        return $data;
    }

    private function hasCoordinatePair(mixed $latitude, mixed $longitude): bool
    {
        return $this->validCoordinate($latitude, -90, 90) && $this->validCoordinate($longitude, -180, 180);
    }

    /** @param array<string, mixed> $data */
    private function coordinatesChanged(Deployment $deployment, array $data): bool
    {
        $latitude = array_key_exists('latitude', $data) ? $data['latitude'] : $deployment->latitude;
        $longitude = array_key_exists('longitude', $data) ? $data['longitude'] : $deployment->longitude;

        return $this->coordinateForComparison($deployment->latitude) !== $this->coordinateForComparison($latitude)
            || $this->coordinateForComparison($deployment->longitude) !== $this->coordinateForComparison($longitude);
    }

    private function coordinateForComparison(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) ? number_format($coordinate, 7, '.', '') : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function phoneCountryFromDeploymentData(array $data, ?Deployment $deployment = null): ?string
    {
        $locationLabel = array_key_exists('location_label', $data)
            ? $data['location_label']
            : $deployment?->location_label;
        $country = ProfileLocation::countryFromLocationLabel(is_string($locationLabel) ? $locationLabel : null);
        if ($country !== null) {
            return $country;
        }

        return ProfileLocation::countryFromCoordinates(
            $data['latitude'] ?? $deployment?->latitude,
            $data['longitude'] ?? $deployment?->longitude,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeDeploymentPhoneFields(array $data, ?string $phoneCountry): array
    {
        foreach (['reporter_phone', 'on_scene_contact_phone'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $data[$field] = PhoneNumber::normalize($data[$field] ?? null, $phoneCountry, $field, allowLocalWithoutCountry: $phoneCountry === null);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutClientControlledReferenceFields(array $data): array
    {
        unset($data['reference'], $data['reference_sequence']);

        return $data;
    }

    private function validCoordinate(mixed $value, float $minimum, float $maximum): bool
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return false;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) && $coordinate >= $minimum && $coordinate <= $maximum;
    }

    private function refreshDroneFlightContextWhenLocated(Deployment $deployment): void
    {
        try {
            $context = $this->droneFlightContextService->previewForDeployment($deployment);
        } catch (Throwable $exception) {
            report($exception);
            $context = [
                'generated_at' => ApiDateTime::now(),
                'location' => [
                    'label' => $deployment->location_label,
                    'latitude' => $deployment->latitude,
                    'longitude' => $deployment->longitude,
                ],
                'airspace' => [
                    'provider' => 'Aeret Drone PreFlight',
                    'status' => 'unavailable',
                    'summary' => 'Drone vluchtcheck kon niet worden opgehaald. Controleer Aeret handmatig.',
                    'no_fly_zones' => [],
                    'notams' => [],
                    'restrictions' => [],
                    'errors' => ['Aeret/NOTAM gegevens konden niet worden opgehaald.'],
                ],
                'weather' => [
                    'provider' => 'Open-Meteo',
                    'status' => 'unavailable',
                    'summary' => 'Weerdata kon niet worden opgehaald.',
                    'errors' => ['Weerdata kon niet worden opgehaald.'],
                ],
                'checklist' => [],
            ];
        }

        $deployment->forceFill(['drone_flight_context' => $context])->save();
    }

    private function resetAcceptedRecipientsToScheduledAvailability(Deployment $deployment, User $actor, string $terminalStatus): void
    {
        $deployment->load([
            'dispatchRequests.recipients.user',
            'pilotAssignments.user',
        ]);

        $dispatchParticipants = $deployment->dispatchRequests
            ->whereIn('status', ['sent', 'escalated'])
            ->flatMap(fn ($dispatch) => $dispatch->recipients)
            ->filter(fn ($recipient): bool => $recipient->response_status === 'accepted'
                && $recipient->user !== null)
            ->map(fn ($recipient) => $recipient->user);
        $manualParticipants = $deployment->pilotAssignments
            ->pluck('user')
            ->filter();

        $dispatchParticipants
            ->merge($manualParticipants)
            ->unique('id')
            ->each(function (User $participant) use ($actor, $terminalStatus): void {
                try {
                    $scheduledAvailable = $this->availabilityScheduleResolver
                        ->availabilityFor($participant)['is_available'];
                    // Push disabled is always authoritative unavailability,
                    // including for a manually linked participant who never
                    // needed a reachable device to join the deployment.
                    $isAvailable = (bool) $participant->push_enabled && $scheduledAvailable;
                    $targetStatus = $isAvailable ? 'available' : 'unavailable';
                    $reason = match ($terminalStatus) {
                        'resolved' => $isAvailable
                            ? 'Inzet afgerond; gebruiker automatisch weer beschikbaar gezet.'
                            : 'Inzet afgerond; gebruiker volgens de beschikbaarheidsplanning niet beschikbaar gezet.',
                        'deleted' => $isAvailable
                            ? 'Inzet verwijderd; gebruiker automatisch weer beschikbaar gezet.'
                            : 'Inzet verwijderd; gebruiker volgens de beschikbaarheidsplanning niet beschikbaar gezet.',
                        default => $isAvailable
                            ? 'Inzet geannuleerd; gebruiker automatisch weer beschikbaar gezet.'
                            : 'Inzet geannuleerd; gebruiker volgens de beschikbaarheidsplanning niet beschikbaar gezet.',
                    };
                    if (! $participant->push_enabled) {
                        $reason = match ($terminalStatus) {
                            'resolved' => 'Inzet afgerond; gebruiker wegens uitgeschakelde push niet beschikbaar gezet.',
                            'deleted' => 'Inzet verwijderd; gebruiker wegens uitgeschakelde push niet beschikbaar gezet.',
                            default => 'Inzet geannuleerd; gebruiker wegens uitgeschakelde push niet beschikbaar gezet.',
                        };
                    }

                    $this->statusService->setStatus($participant, $targetStatus, $actor, $reason, true);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }

    private function broadcastDeploymentChange(Deployment $deployment, string $action): void
    {
        DB::afterCommit(function () use ($deployment, $action): void {
            try {
                DeploymentChanged::dispatch($deployment->refresh(), $action);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
