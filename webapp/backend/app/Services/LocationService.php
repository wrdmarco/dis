<?php

namespace App\Services;

use App\Events\LocationUpdated;
use App\Events\IncidentChanged;
use App\Jobs\SendFcmNotification;
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
        $this->ensureIncidentAllowsLocationSharing($incident);

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

    /**
     * @return array{queued_tokens: int, user_id: string}
     */
    public function requestSharing(Incident $incident, User $target, User $actor): array
    {
        $this->ensureIncidentAllowsLocationSharing($incident);
        $this->ensureAcceptedRecipient($incident, $target);

        $consent = LocationSharingConsent::query()->updateOrCreate(
            ['incident_id' => $incident->id, 'user_id' => $target->id],
            [
                'is_active' => false,
                'consented_at' => now(),
                'revoked_at' => null,
                'declined_at' => null,
                'refusal_reason' => null,
            ],
        );

        $tokens = $target->fcmTokens()->where('is_active', true)->get();
        if ($tokens->isEmpty()) {
            throw ValidationException::withMessages(['user_id' => ['Deze gebruiker heeft geen actief app-device voor pushmeldingen.']]);
        }

        foreach ($tokens as $token) {
            SendFcmNotification::dispatch(
                (string) $token->id,
                'location_share_request',
                'Locatie delen gevraagd',
                'Open het incident om je locatie te delen.',
                [
                    'type' => 'location_share_request',
                    'incident_id' => (string) $incident->id,
                    'incident_reference' => (string) $incident->reference,
                    'incident_title' => (string) $incident->title,
                    'request_location_consent' => 'true',
                ],
                null,
            )->onQueue('push');
        }

        $this->auditService->record('location.share_requested', $incident, $actor, [
            'user_id' => $target->id,
            'consent_id' => $consent->id,
            'queued_tokens' => $tokens->count(),
        ]);
        $this->broadcastLocationSharingChange($incident);

        return ['queued_tokens' => $tokens->count(), 'user_id' => (string) $target->id];
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
        $this->ensureIncidentAllowsLocationSharing($incident);

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
            $this->broadcastLocationSharingChange($incident);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $location;
    }

    public function stopForIncident(Incident $incident, User $actor): void
    {
        $updated = LocationSharingConsent::query()
            ->where('incident_id', $incident->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'revoked_at' => now()]);

        if ($updated === 0) {
            return;
        }

        $this->auditService->record('location.sharing_stopped_for_incident', $incident, $actor, ['consent_count' => $updated]);
        $this->broadcastLocationSharingChange($incident);
    }

    public function isClosedForLocationSharing(Incident $incident): bool
    {
        return in_array($incident->status, ['resolved', 'cancelled'], true);
    }

    private function ensureIncidentAllowsLocationSharing(Incident $incident): void
    {
        if ($this->isClosedForLocationSharing($incident)) {
            throw ValidationException::withMessages(['incident_id' => ['Live locatie delen is gestopt voor afgeronde of geannuleerde incidenten.']]);
        }
    }

    private function ensureAcceptedRecipient(Incident $incident, User $target): void
    {
        $hasActiveConsent = LocationSharingConsent::query()
            ->where('incident_id', $incident->id)
            ->where('user_id', $target->id)
            ->where('is_active', true)
            ->exists();

        if ($hasActiveConsent) {
            return;
        }

        $isAcceptedRecipient = $incident->dispatchRequests()
            ->whereIn('status', ['sent', 'escalated'])
            ->whereHas('recipients', fn ($recipients) => $recipients
                ->where('user_id', $target->id)
                ->where('response_status', 'accepted'))
            ->exists();

        if (! $isAcceptedRecipient) {
            throw ValidationException::withMessages(['user_id' => ['Locatie delen kan alleen worden gevraagd aan gebruikers die opkomen voor dit incident.']]);
        }
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
