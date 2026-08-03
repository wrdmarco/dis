<?php

namespace App\Services;

use App\Events\DeploymentChanged;
use App\Events\LocationUpdated;
use App\Jobs\SendFcmNotification;
use App\Models\Deployment;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
use App\Models\User;
use App\Support\ApiDateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LocationService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function consent(Deployment $deployment, User $user): LocationSharingConsent
    {
        $this->ensureDeploymentAllowsLocationSharing($deployment);
        $this->ensureAcceptedRecipient($deployment, $user);

        $consent = DB::transaction(function () use ($deployment, $user): LocationSharingConsent {
            // Serialise the first consent-row creation for this deployment. Once
            // present, the consent row itself is the lock shared with location
            // updates, revoke and re-consent operations.
            $lockedDeployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            $this->ensureDeploymentAllowsLocationSharing($lockedDeployment);
            $this->ensureAcceptedRecipient($lockedDeployment, $user);
            $consent = $this->lockedConsent($deployment, $user) ?? new LocationSharingConsent([
                'deployment_id' => $deployment->id,
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

        $this->auditService->record('location.consent_enabled', $deployment, $user);
        $this->broadcastLocationSharingChange($deployment);

        return $consent;
    }

    public function decline(Deployment $deployment, User $user, ?string $reason): LocationSharingConsent
    {
        $consent = DB::transaction(function () use ($deployment, $user, $reason): LocationSharingConsent {
            Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            $consent = $this->lockedConsent($deployment, $user) ?? new LocationSharingConsent([
                'deployment_id' => $deployment->id,
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

        $this->auditService->record('location.consent_declined', $deployment, $user, ['reason' => $reason]);
        $this->broadcastLocationSharingChange($deployment);

        return $consent;
    }

    /**
     * @return array{queued_tokens: int, user_id: string}
     */
    public function requestSharing(Deployment $deployment, User $target, User $actor): array
    {
        $this->ensureDeploymentAllowsLocationSharing($deployment);
        $this->ensureAcceptedRecipient($deployment, $target);

        [$consent, $tokens] = DB::transaction(function () use ($deployment, $target): array {
            $lockedDeployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            $this->ensureDeploymentAllowsLocationSharing($lockedDeployment);
            $this->ensureAcceptedRecipient($lockedDeployment, $target);
            $consent = $this->lockedConsent($deployment, $target) ?? new LocationSharingConsent([
                'deployment_id' => $deployment->id,
                'user_id' => $target->id,
            ]);
            if ($consent->exists && $consent->is_active && $this->hasCurrentLocation($consent)) {
                return [$consent, collect()];
            }

            $tokens = $target->fcmTokens()->where('is_active', true)->get();
            if ($tokens->isEmpty()) {
                throw ValidationException::withMessages(['user_id' => ['Deze gebruiker heeft geen actief app-device voor pushmeldingen.']]);
            }
            if ($consent->exists && $consent->is_active) {
                return [$consent, $tokens];
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
                'Open de inzet om je locatie te delen.',
                [
                    'type' => 'location_share_request',
                    'deployment_id' => (string) $deployment->id,
                    'deployment_reference' => (string) $deployment->reference,
                    'deployment_title' => (string) $deployment->title,
                    'request_location_consent' => 'true',
                ],
                null,
            )->onQueue('push');
        }

        $this->auditService->record('location.share_requested', $deployment, $actor, [
            'user_id' => $target->id,
            'consent_id' => $consent->id,
            'queued_tokens' => $tokens->count(),
        ]);
        $this->broadcastLocationSharingChange($deployment);

        return ['queued_tokens' => $tokens->count(), 'user_id' => (string) $target->id];
    }

    public function revoke(Deployment $deployment, User $user): void
    {
        $this->revokeForDeployment($deployment, $user, $user);
    }

    public function revokeForDeployment(Deployment $deployment, User $target, User $actor): void
    {
        $revoked = DB::transaction(function () use ($deployment, $target): bool {
            Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            $consent = $this->lockedConsent($deployment, $target);
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

        $this->auditService->record('location.consent_revoked', $deployment, $actor, ['user_id' => $target->id]);

        // A caller such as manual-participant removal may own a wider
        // transaction. The consent mutation remains atomic with that workflow,
        // while realtime consumers only observe it after the outer commit.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->broadcastLocationSharingChange($deployment));

            return;
        }

        $this->broadcastLocationSharingChange($deployment);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLocation(Deployment $deployment, User $user, array $data): LocationUpdate
    {
        $this->ensureDeploymentAllowsLocationSharing($deployment);
        $this->ensureAcceptedRecipient($deployment, $user);

        $consentSnapshot = LocationSharingConsent::query()
            ->where('deployment_id', $deployment->id)
            ->where('user_id', $user->id)
            ->first();
        if ($consentSnapshot?->is_active !== true) {
            throw ValidationException::withMessages(['location' => ['Live locatie delen vereist toestemming voor deze inzet.']]);
        }
        $consentStateVersion = (int) $consentSnapshot->state_version;

        $location = DB::transaction(function () use ($deployment, $user, $data, $consentStateVersion): LocationUpdate {
            $consent = $this->lockedConsent($deployment, $user);
            if ($consent?->is_active !== true || (int) $consent->state_version !== $consentStateVersion) {
                throw ValidationException::withMessages(['location' => ['Live locatie delen vereist toestemming voor deze inzet.']]);
            }
            $this->ensureDeploymentAllowsLocationSharing(Deployment::query()->findOrFail($deployment->id));
            $this->ensureAcceptedRecipient($deployment, $user);

            // The location insert and consent validation share one row lock.
            // Revoke therefore either happens strictly before this check (and
            // rejects it) or strictly after the insert (and immediately hides
            // it), also across multiple application instances.
            return LocationUpdate::query()->create(array_merge($data, [
                'deployment_id' => $deployment->id,
                'user_id' => $user->id,
                'consent_state_version' => $consentStateVersion,
                'recorded_at' => $data['recorded_at'] ?? now(),
                'created_at' => now(),
            ]));
        });

        try {
            LocationUpdated::dispatch($location);
            $this->broadcastLocationSharingChange($deployment);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $location;
    }

    public function stopForDeployment(Deployment $deployment, User $actor): void
    {
        [$activeConsents, $updated] = DB::transaction(function () use ($deployment): array {
            Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            $activeConsents = LocationSharingConsent::query()
                ->with(['user.fcmTokens' => fn ($tokens) => $tokens->where('is_active', true)])
                ->where('deployment_id', $deployment->id)
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

        $this->auditService->record('location.sharing_stopped_for_deployment', $deployment, $actor, ['consent_count' => $updated]);
        $this->sendLocationSharingStoppedNotifications($deployment, $activeConsents);
        $this->broadcastLocationSharingChange($deployment);
    }

    public function stopForUser(User $user, User $actor): void
    {
        $activeConsents = DB::transaction(function () use ($user) {
            $activeConsents = LocationSharingConsent::query()
                ->with(['deployment', 'user.fcmTokens' => fn ($tokens) => $tokens->where('is_active', true)])
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

        foreach ($activeConsents->groupBy('deployment_id') as $deploymentConsents) {
            $deployment = $deploymentConsents->first()?->deployment;
            if ($deployment === null) {
                continue;
            }

            $this->auditService->record('location.sharing_stopped_for_user', $deployment, $actor, [
                'user_id' => $user->id,
                'user_name' => $user->name,
            ]);
            $this->sendLocationSharingStoppedNotifications($deployment, $deploymentConsents);
            $this->broadcastLocationSharingChange($deployment);
        }
    }

    public function isClosedForLocationSharing(Deployment $deployment): bool
    {
        return in_array($deployment->status, ['resolved', 'cancelled'], true);
    }

    private function ensureDeploymentAllowsLocationSharing(Deployment $deployment): void
    {
        if ($this->isClosedForLocationSharing($deployment)) {
            throw ValidationException::withMessages(['deployment_id' => ['Live locatie delen is gestopt voor afgeronde of geannuleerde inzetten.']]);
        }
    }

    private function ensureAcceptedRecipient(Deployment $deployment, User $target): void
    {
        $isAcceptedRecipient = $deployment->dispatchRequests()
            ->whereIn('status', ['sent', 'escalated'])
            ->whereHas('recipients', fn ($recipients) => $recipients
                ->where('user_id', $target->id)
                ->where('response_status', 'accepted'))
            ->exists();
        $isManualParticipant = $deployment->pilotAssignments()
            ->where('user_id', $target->id)
            ->exists();

        if (! $isAcceptedRecipient && ! $isManualParticipant) {
            throw ValidationException::withMessages(['user_id' => ['Locatie delen kan alleen worden gevraagd aan gebruikers die voor deze inzet opkomen.']]);
        }

        if ($target->statuses()->latestPerUser()->value('status') === 'on_scene') {
            throw ValidationException::withMessages(['user_id' => ['Live locatie delen stopt zodra de gebruiker op locatie is.']]);
        }
    }

    private function lockedConsent(Deployment $deployment, User $user): ?LocationSharingConsent
    {
        return LocationSharingConsent::query()
            ->where('deployment_id', $deployment->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
    }

    private function nextConsentStateVersion(LocationSharingConsent $consent): int
    {
        return $consent->exists ? max(1, (int) $consent->state_version + 1) : 1;
    }

    private function hasCurrentLocation(LocationSharingConsent $consent): bool
    {
        $location = LocationUpdate::query()
            ->where('deployment_id', $consent->deployment_id)
            ->where('user_id', $consent->user_id)
            ->where('consent_state_version', $consent->state_version)
            ->latest('created_at')
            ->latest('id')
            ->first();
        if ($location?->recorded_at === null || $location->created_at === null || $consent->consented_at === null) {
            return false;
        }

        $recordedAt = ApiDateTime::localWallClock($location->recorded_at);
        $createdAt = ApiDateTime::localWallClock($location->created_at);
        $consentedAt = ApiDateTime::localWallClock($consent->consented_at);
        $now = now();

        return $createdAt?->greaterThanOrEqualTo($consentedAt) === true
            && $recordedAt?->lessThanOrEqualTo($createdAt->addMinutes(2)) === true
            && $recordedAt->betweenIncluded($now->copy()->subMinutes(5), $now->copy()->addMinutes(2))
            && $createdAt->betweenIncluded($now->copy()->subMinutes(5), $now->copy()->addMinutes(2));
    }

    private function broadcastLocationSharingChange(Deployment $deployment): void
    {
        try {
            DeploymentChanged::dispatch($deployment->refresh(), 'location_sharing_changed');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function sendLocationSharingStoppedNotifications(Deployment $deployment, iterable $activeConsents): void
    {
        foreach ($activeConsents as $consent) {
            foreach ($consent->user?->fcmTokens ?? [] as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'location_sharing_stopped',
                    'Live locatie gestopt',
                    'Live locatie delen is gestopt voor deze inzet.',
                    [
                        'type' => 'location_sharing_stopped',
                        'deployment_id' => (string) $deployment->id,
                        'deployment_reference' => (string) $deployment->reference,
                    ],
                    null,
                )->onQueue('push');
            }
        }
    }
}
