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

            $eligible = $this->eligibleUsers($targetTeam);
            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages(['team_code' => ['Geen beschikbare gebruikers met actieve push-token gevonden voor dit team.']]);
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

    private function eligibleUsers(Team $targetTeam)
    {
        $requiredCertificationIds = Certification::query()
            ->where('is_required_for_dispatch', true)
            ->pluck('id');
        $teamCodes = $this->expandTeamCodes($targetTeam);

        return User::query()
            ->where('account_status', 'active')
            ->where('push_enabled', true)
            ->whereHas('teams', fn ($teams) => $teams->whereIn('code', $teamCodes))
            ->whereHas('fcmTokens', fn ($tokens) => $tokens->where('is_active', true))
            ->whereHas('statuses', fn ($statuses) => $statuses->latestPerUser()->where('is_available', true))
            ->get()
            ->filter(function (User $user) use ($requiredCertificationIds): bool {
                foreach ($requiredCertificationIds as $certificationId) {
                    $hasActiveCertification = $user->certifications()
                        ->where('certification_id', $certificationId)
                        ->where('status', 'active')
                        ->where(function ($query): void {
                            $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()->toDateString());
                        })
                        ->exists();

                    if (! $hasActiveCertification) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
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
