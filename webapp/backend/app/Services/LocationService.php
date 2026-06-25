<?php

namespace App\Services;

use App\Events\LocationUpdated;
use App\Events\IncidentChanged;
use App\Models\Incident;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LocationService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function consent(Incident $incident, User $user): LocationSharingConsent
    {
        $consent = LocationSharingConsent::query()->updateOrCreate(
            ['incident_id' => $incident->id, 'user_id' => $user->id],
            ['is_active' => true, 'consented_at' => now(), 'revoked_at' => null, 'declined_at' => null, 'refusal_reason' => null],
        );

        $this->auditService->record('location.consent_enabled', $incident, $user);
        $this->broadcastLocationSharingChange($incident);

        return $consent;
    }

    public function decline(Incident $incident, User $user, ?string $reason): LocationSharingConsent
    {
        $consent = LocationSharingConsent::query()->updateOrCreate(
            ['incident_id' => $incident->id, 'user_id' => $user->id],
            [
                'is_active' => false,
                'revoked_at' => null,
                'declined_at' => now(),
                'refusal_reason' => $reason,
            ],
        );

        $this->auditService->record('location.consent_declined', $incident, $user, ['reason' => $reason]);
        $this->broadcastLocationSharingChange($incident);

        return $consent;
    }

    public function revoke(Incident $incident, User $user): void
    {
        LocationSharingConsent::query()
            ->where('incident_id', $incident->id)
            ->where('user_id', $user->id)
            ->update(['is_active' => false, 'revoked_at' => now()]);

        $this->auditService->record('location.consent_revoked', $incident, $user);
        $this->broadcastLocationSharingChange($incident);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateLocation(Incident $incident, User $user, array $data): LocationUpdate
    {
        $hasConsent = LocationSharingConsent::query()
            ->where('incident_id', $incident->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (! $hasConsent) {
            throw ValidationException::withMessages(['location' => ['Live location sharing requires per-incident consent.']]);
        }

        $location = LocationUpdate::query()->create($data + [
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'recorded_at' => $data['recorded_at'] ?? now(),
            'created_at' => now(),
        ]);

        try {
            LocationUpdated::dispatch($location);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $location;
    }

    private function broadcastLocationSharingChange(Incident $incident): void
    {
        try {
            IncidentChanged::dispatch($incident->refresh(), 'location_sharing_changed');
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
