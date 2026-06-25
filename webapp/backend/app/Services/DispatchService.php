<?php

namespace App\Services;

use App\Events\DispatchChanged;
use App\Jobs\SendFcmNotification;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\Team;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DispatchService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(Incident $incident, array $data, User $actor): DispatchRequest
    {
        if (in_array($incident->status, ['resolved', 'cancelled'], true)) {
            throw ValidationException::withMessages(['incident_id' => ['Cannot dispatch for a closed incident.']]);
        }

        return DB::transaction(function () use ($incident, $data, $actor): DispatchRequest {
            $targetTeam = $this->targetTeam($incident, $data);
            if ($targetTeam === null) {
                throw ValidationException::withMessages(['team_code' => ['Het gekozen team bestaat niet.']]);
            }

            $eligibility = $this->eligibleUsers($targetTeam);
            $eligible = $eligibility['users'];
            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages(['team_code' => [$eligibility['message']]]);
            }

            $dispatch = DispatchRequest::query()->create([
                'incident_id' => $incident->id,
                'requested_by' => $actor->id,
                'target_team_id' => $targetTeam->id,
                'status' => 'draft',
                'priority' => $data['priority'],
                'message' => $data['message'],
            ]);

            foreach ($eligible as $user) {
                DispatchRecipient::query()->create([
                    'dispatch_request_id' => $dispatch->id,
                    'user_id' => $user->id,
                    'response_status' => 'pending',
                ]);
            }

            $this->auditService->record('dispatch.created', $dispatch, $actor, ['recipient_count' => $eligible->count()]);
            DispatchChanged::dispatch($dispatch, 'created');

            return $dispatch->load(['incident', 'recipients']);
        });
    }

    public function markSent(DispatchRequest $dispatch, User $actor): DispatchRequest
    {
        return DB::transaction(function () use ($dispatch, $actor): DispatchRequest {
            $dispatch->update(['status' => 'sent', 'sent_at' => now()]);
            $dispatch->recipients()->whereNull('notified_at')->update(['notified_at' => now()]);
            $dispatch->load(['incident', 'recipients.user.fcmTokens']);

            foreach ($dispatch->recipients as $recipient) {
                foreach ($recipient->user?->fcmTokens->where('is_active', true) ?? [] as $token) {
                    SendFcmNotification::dispatch(
                        (string) $token->id,
                        'dispatch_request',
                        'D.I.S dispatch',
                        $dispatch->incident?->reference.' - '.$dispatch->priority,
                        [
                            'type' => 'dispatch_request',
                            'dispatch_id' => (string) $dispatch->id,
                            'incident_id' => (string) $dispatch->incident_id,
                        ],
                        (string) $dispatch->id,
                    )->onQueue('push');
                }
            }

            $this->auditService->record('dispatch.sent', $dispatch, $actor);
            DispatchChanged::dispatch($dispatch, 'sent');

            return $dispatch->refresh()->load(['recipients']);
        });
    }

    public function respond(DispatchRequest $dispatch, User $actor, string $response, ?string $note): DispatchRecipient
    {
        $recipient = $dispatch->recipients()->where('user_id', $actor->id)->firstOrFail();
        $recipient->update(['response_status' => $response, 'response_note' => $note, 'responded_at' => now()]);
        $this->auditService->record('dispatch.responded', $dispatch, $actor, ['response' => $response]);
        DispatchChanged::dispatch($dispatch->refresh(), 'responded');

        return $recipient;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function targetTeam(Incident $incident, array $data): ?Team
    {
        if (isset($data['target_team_id'])) {
            return Team::query()->find((string) $data['target_team_id']);
        }

        if (isset($data['team_code'])) {
            return Team::query()->where('code', (string) $data['team_code'])->first();
        }

        if ($incident->team_id !== null) {
            return Team::query()->find($incident->team_id);
        }

        return Team::query()->where('code', (string) config('dis.teams.base_team_code', 'OCP'))->first();
    }

    /**
     * @return array{users: Collection<int, User>, message: string}
     */
    private function eligibleUsers(Team $targetTeam): array
    {
        $requiredCertificationIds = Certification::query()
            ->where('is_required_for_dispatch', true)
            ->pluck('id');
        $teamCodes = $this->expandTeamCodes($targetTeam);

        $teamUsers = User::query()
            ->with([
                'certifications',
                'fcmTokens' => fn ($tokens) => $tokens->where('is_active', true),
                'statuses' => fn ($statuses) => $statuses->latestPerUser(),
            ])
            ->whereHas('teams', fn ($teams) => $teams->whereIn('code', $teamCodes))
            ->get();

        $activeUsers = $teamUsers
            ->filter(fn (User $user): bool => $user->account_status === 'active')
            ->values();
        $pushEnabledUsers = $activeUsers
            ->filter(fn (User $user): bool => (bool) $user->push_enabled)
            ->values();
        $tokenUsers = $pushEnabledUsers
            ->filter(fn (User $user): bool => $user->fcmTokens->isNotEmpty())
            ->values();
        $availableUsers = $tokenUsers
            ->filter(fn (User $user): bool => $user->statuses->first()?->is_available === true)
            ->values();
        $certifiedUsers = $availableUsers
            ->filter(fn (User $user): bool => $this->hasRequiredCertifications($user, $requiredCertificationIds))
            ->values();

        return [
            'users' => $certifiedUsers,
            'message' => $this->eligibilityFailureMessage($targetTeam, [
                'team_users' => $teamUsers->count(),
                'active_users' => $activeUsers->count(),
                'push_enabled_users' => $pushEnabledUsers->count(),
                'active_token_users' => $tokenUsers->count(),
                'available_users' => $availableUsers->count(),
                'certified_users' => $certifiedUsers->count(),
                'required_certifications' => $requiredCertificationIds->count(),
            ]),
        ];
    }

    /**
     * @param Collection<int, string> $requiredCertificationIds
     */
    private function hasRequiredCertifications(User $user, Collection $requiredCertificationIds): bool
    {
        foreach ($requiredCertificationIds as $certificationId) {
            $hasActiveCertification = $user->certifications->contains(
                fn ($certification): bool => $certification->certification_id === $certificationId
                    && $certification->status === 'active'
                    && ($certification->expires_at === null || $certification->expires_at->greaterThanOrEqualTo(now()->toDateString())),
            );

            if (! $hasActiveCertification) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{team_users: int, active_users: int, push_enabled_users: int, active_token_users: int, available_users: int, certified_users: int, required_certifications: int} $counts
     */
    private function eligibilityFailureMessage(Team $team, array $counts): string
    {
        $prefix = "Geen alarmeerbare gebruikers gevonden voor team {$team->code}.";

        if ($counts['team_users'] === 0) {
            return "$prefix Er zijn geen gebruikers aan dit team gekoppeld.";
        }

        if ($counts['active_users'] === 0) {
            return "$prefix Teamleden hebben geen actieve accountstatus.";
        }

        if ($counts['push_enabled_users'] === 0) {
            return "$prefix Teamleden hebben pushmeldingen niet ingeschakeld.";
        }

        if ($counts['active_token_users'] === 0) {
            return "$prefix Teamleden hebben geen actieve push-token.";
        }

        if ($counts['available_users'] === 0) {
            return "$prefix Teamleden zijn niet beschikbaar volgens hun laatste status.";
        }

        if ($counts['required_certifications'] > 0 && $counts['certified_users'] === 0) {
            return "$prefix Beschikbare teamleden missen een verplichte geldige certificering.";
        }

        return "$prefix Controleer team, push-token, beschikbaarheid en certificeringen.";
    }

    /**
     * @return array<int, string>
     */
    private function expandTeamCodes(Team $team): array
    {
        $team->loadMissing('alertTeams:id,code');

        return array_values(array_unique([
            $team->code,
            ...$team->alertTeams->pluck('code')->all(),
        ]));
    }
}
