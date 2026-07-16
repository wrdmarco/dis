<?php

namespace App\Services;

use App\Events\IncidentChanged;
use App\Events\LocationUpdated;
use App\Jobs\SendFcmNotification;
use App\Models\Incident;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LocationService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function consent(Incident $incident, User $user): LocationSharingConsent
    {
        $this->ensureIncidentAllowsLocationSharing($incident);
        $this->ensureAcceptedRecipient($incident, $user);

        $consent = DB::transaction(function () use ($incident, $user): LocationSharingConsent {
            // Serialise the first consent-row creation for this incident. Once
            // present, the consent row itself is the lock shared with location
            // updates, revoke and re-consent operations.
            $lockedIncident = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $this->ensureIncidentAllowsLocationSharing($lockedIncident);
            $this->ensureAcceptedRecipient($lockedIncident, $user);
            $consent = $this->lockedConsent($incident, $user) ?? new LocationSharingConsent([
                'incident_id' => $incident->id,
                'user_id' => $user->id,
            ]);
            if ($consent->exists && $consent->is_active) {
                return $consent;
            }
            $consent->fill([
                'is_active' => true,
                'state_version' => $this->nextConsentStateVersion($consent),
                'consented_at' => now(),
                'revoked_at' => null,
                'declined_at' => null,
                'refusal_reason' => null,
            ])->save();

            return $consent;
        });

        $this->auditService->record('location.consent_enabled', $incident, $user);
        $this->broadcastLocationSharingChange($incident);

        return $consent;
    }

    public function decline(Incident $incident, User $user, ?string $reason): LocationSharingConsent
    {
        $consent = DB::transaction(function () use ($incident, $user, $reason): LocationSharingConsent {
            Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $consent = $this->lockedConsent($incident, $user) ?? new LocationSharingConsent([
                'incident_id' => $incident->id,
                'user_id' => $user->id,
            ]);
            $consent->fill([
                'is_active' => false,
                'state_version' => $this->nextConsentStateVersion($consent),
                'revoked_at' => null,
                'declined_at' => now(),
                'refusal_reason' => $reason,
            ])->save();

            return $consent;
        });

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

        [$consent, $tokens] = DB::transaction(function () use ($incident, $target): array {
            $lockedIncident = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $this->ensureIncidentAllowsLocationSharing($lockedIncident);
            $this->ensureAcceptedRecipient($lockedIncident, $target);
            $consent = $this->lockedConsent($incident, $target) ?? new LocationSharingConsent([
                'incident_id' => $incident->id,
                'user_id' => $target->id,
            ]);
            if ($consent->exists && $consent->is_active) {
                return [$consent, collect()];
            }

            $tokens = $target->fcmTokens()->where('is_active', true)->get();
            if ($tokens->isEmpty()) {
                throw ValidationException::withMessages(['user_id' => ['Deze gebruiker heeft geen actief app-device voor pushmeldingen.']]);
            }
            $consent->fill([
                'is_active' => false,
                'state_version' => $this->nextConsentStateVersion($consent),
                'consented_at' => now(),
                'revoked_at' => null,
                'declined_at' => null,
                'refusal_reason' => null,
            ])->save();

            return [$consent, $tokens];
        });

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
        $this->revokeForIncident($incident, $user, $user);
    }

    public function revokeForIncident(Incident $incident, User $target, User $actor): void
    {
        $revoked = DB::transaction(function () use ($incident, $target): bool {
            Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $consent = $this->lockedConsent($incident, $target);
            if ($consent === null || ! $consent->is_active) {
                return false;
            }

            $consent->forceFill([
                'is_active' => false,
                'state_version' => $this->nextConsentStateVersion($consent),
                'revoked_at' => now(),
            ])->save();

            return true;
        });

        if (! $revoked) {
            return;
        }

        $this->auditService->record('location.consent_revoked', $incident, $actor, ['user_id' => $target->id]);
        $this->broadcastLocationSharingChange($incident);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLocation(Incident $incident, User $user, array $data): LocationUpdate
    {
        $this->ensureIncidentAllowsLocationSharing($incident);
        $this->ensureAcceptedRecipient($incident, $user);

        $consentSnapshot = LocationSharingConsent::query()
            ->where('incident_id', $incident->id)
            ->where('user_id', $user->id)
            ->first();
        if ($consentSnapshot?->is_active !== true) {
            throw ValidationException::withMessages(['location' => ['Live location sharing requires per-incident consent.']]);
        }
        $consentStateVersion = (int) $consentSnapshot->state_version;

        $location = DB::transaction(function () use ($incident, $user, $data, $consentStateVersion): LocationUpdate {
            $consent = $this->lockedConsent($incident, $user);
            if ($consent?->is_active !== true || (int) $consent->state_version !== $consentStateVersion) {
                throw ValidationException::withMessages(['location' => ['Live location sharing requires per-incident consent.']]);
            }
            $this->ensureIncidentAllowsLocationSharing(Incident::query()->findOrFail($incident->id));
            $this->ensureAcceptedRecipient($incident, $user);

            // The location insert and consent validation share one row lock.
            // Revoke therefore either happens strictly before this check (and
            // rejects it) or strictly after the insert (and immediately hides
            // it), also across multiple application instances.
            return LocationUpdate::query()->create(array_merge($data, [
                'incident_id' => $incident->id,
                'user_id' => $user->id,
                'consent_state_version' => $consentStateVersion,
                'recorded_at' => $data['recorded_at'] ?? now(),
                'created_at' => now(),
            ]));
        });

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
        [$activeConsents, $updated] = DB::transaction(function () use ($incident): array {
            Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $activeConsents = LocationSharingConsent::query()
                ->with(['user.fcmTokens' => fn ($tokens) => $tokens->where('is_active', true)])
                ->where('incident_id', $incident->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $updated = LocationSharingConsent::query()
                ->whereIn('id', $activeConsents->pluck('id'))
                ->update([
                    'is_active' => false,
                    'state_version' => DB::raw('state_version + 1'),
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            return [$activeConsents, $updated];
        });

        if ($updated === 0) {
            return;
        }

        $this->auditService->record('location.sharing_stopped_for_incident', $incident, $actor, ['consent_count' => $updated]);
        $this->sendLocationSharingStoppedNotifications($incident, $activeConsents);
        $this->broadcastLocationSharingChange($incident);
    }

    public function stopForUser(User $user, User $actor): void
    {
        $activeConsents = DB::transaction(function () use ($user) {
            $activeConsents = LocationSharingConsent::query()
                ->with(['incident', 'user.fcmTokens' => fn ($tokens) => $tokens->where('is_active', true)])
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            LocationSharingConsent::query()
                ->whereIn('id', $activeConsents->pluck('id'))
                ->update([
                    'is_active' => false,
                    'state_version' => DB::raw('state_version + 1'),
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            return $activeConsents;
        });

        if ($activeConsents->isEmpty()) {
            return;
        }

        foreach ($activeConsents->groupBy('incident_id') as $incidentConsents) {
            $incident = $incidentConsents->first()?->incident;
            if ($incident === null) {
                continue;
            }

            $this->auditService->record('location.sharing_stopped_for_user', $incident, $actor, [
                'user_id' => $user->id,
                'user_name' => $user->name,
            ]);
            $this->sendLocationSharingStoppedNotifications($incident, $incidentConsents);
            $this->broadcastLocationSharingChange($incident);
        }
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
        $isAcceptedRecipient = $incident->dispatchRequests()
            ->whereIn('status', ['sent', 'escalated'])
            ->whereHas('recipients', fn ($recipients) => $recipients
                ->where('user_id', $target->id)
                ->where('response_status', 'accepted'))
            ->exists();

        if (! $isAcceptedRecipient) {
            throw ValidationException::withMessages(['user_id' => ['Locatie delen kan alleen worden gevraagd aan gebruikers die opkomen voor dit incident.']]);
        }

        if ($target->statuses()->latestPerUser()->value('status') === 'on_scene') {
            throw ValidationException::withMessages(['user_id' => ['Live locatie delen stopt zodra de gebruiker op locatie is.']]);
        }
    }

    private function lockedConsent(Incident $incident, User $user): ?LocationSharingConsent
    {
        return LocationSharingConsent::query()
            ->where('incident_id', $incident->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
    }

    private function nextConsentStateVersion(LocationSharingConsent $consent): int
    {
        return $consent->exists ? max(1, (int) $consent->state_version + 1) : 1;
    }

    private function broadcastLocationSharingChange(Incident $incident): void
    {
        try {
            IncidentChanged::dispatch($incident->refresh(), 'location_sharing_changed');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function sendLocationSharingStoppedNotifications(Incident $incident, iterable $activeConsents): void
    {
        foreach ($activeConsents as $consent) {
            foreach ($consent->user?->fcmTokens ?? [] as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'location_sharing_stopped',
                    'Live locatie gestopt',
                    'Live locatie delen is gestopt voor dit incident.',
                    [
                        'type' => 'location_sharing_stopped',
                        'incident_id' => (string) $incident->id,
                        'incident_reference' => (string) $incident->reference,
                    ],
                    null,
                )->onQueue('push');
            }
        }
    }
}
