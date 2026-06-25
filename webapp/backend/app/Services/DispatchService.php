<?php

namespace App\Services;

use App\Events\DispatchChanged;
use App\Jobs\SendFcmNotification;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\Team;
use App\Models\User;
use App\Models\Certification;
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
            $teamCode = (string) ($data['team_code'] ?? 'OCP');
            $targetTeam = Team::query()->where('code', $teamCode)->first();
            if ($targetTeam === null) {
                throw ValidationException::withMessages(['team_code' => ['Het gekozen team bestaat niet.']]);
            }

            $eligible = $this->eligibleUsers($teamCode);
            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages(['team_code' => ['Geen beschikbare gebruikers met actieve push-token gevonden voor dit team.']]);
            }

            $dispatch = DispatchRequest::query()->create([
                'incident_id' => $incident->id,
                'requested_by' => $actor->id,
                'target_team_id' => $data['target_team_id'] ?? $targetTeam->id,
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

    private function eligibleUsers(string $teamCode)
    {
        $requiredCertificationIds = Certification::query()
            ->where('is_required_for_dispatch', true)
            ->pluck('id');
        $teamCodes = $this->expandTeamCodes($teamCode);

        return User::query()
            ->where('account_status', 'active')
            ->where('push_enabled', true)
            ->whereHas('teams', fn ($teams) => $teams->whereIn('code', $teamCodes))
            ->whereHas('fcmTokens', fn ($tokens) => $tokens->where('is_active', true))
            ->whereHas('statuses', fn ($statuses) => $statuses->where('is_available', true))
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
    private function expandTeamCodes(string $teamCode): array
    {
        $team = Team::query()->where('code', $teamCode)->with('alertTeams:id,code')->first();

        if ($team === null) {
            return [$teamCode];
        }

        return array_values(array_unique([
            $team->code,
            ...$team->alertTeams->pluck('code')->all(),
        ]));
    }
}
