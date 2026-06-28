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
use Throwable;

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
            $this->broadcastDispatchChange($dispatch, 'created');

            return $dispatch->load(['incident', 'recipients']);
        });
    }

    public function createAndSendForIncidentActivation(Incident $incident, User $actor, ?string $message = null): ?DispatchRequest
    {
        if ($incident->dispatchRequests()->whereIn('status', ['draft', 'sent'])->exists()) {
            return null;
        }

        $dispatch = null;
        foreach ($this->targetTeams($incident, []) as $targetTeam) {
            $created = $this->create($incident, [
                'priority' => $incident->priority === 'low' ? 'normal' : $incident->priority,
                'message' => $message ?: $this->defaultDispatchMessage($incident),
                'target_team_id' => $targetTeam->id,
            ], $actor);

            $sent = $this->markSent($created, $actor);
            $dispatch ??= $sent;
        }

        return $dispatch;
    }

    /**
     * @return array{team: array<string, mixed>|null, recipients: list<array<string, mixed>>, blocked_reason: string|null}
     */
    public function previewForIncident(Incident $incident): array
    {
        $targetTeams = $this->targetTeams($incident, []);
        if ($targetTeams->isEmpty()) {
            return [
                'team' => null,
                'teams' => [],
                'recipients' => [],
                'blocked_reason' => 'Er is geen geldig team voor deze melding gekozen.',
            ];
        }

        $eligibleUsers = collect();
        $blockedReasons = [];
        foreach ($targetTeams as $targetTeam) {
            $eligibility = $this->eligibleUsers($targetTeam);
            $eligibleUsers = $eligibleUsers->merge($eligibility['users']);
            if ($eligibility['users']->isEmpty()) {
                $blockedReasons[] = $eligibility['message'];
            }
        }
        $eligibleUsers = $eligibleUsers->unique('id')->values();
        $primaryTeam = $targetTeams->first();

        return [
            'team' => [
                'id' => $primaryTeam->id,
                'code' => $primaryTeam->code,
                'name' => $primaryTeam->name,
            ],
            'teams' => $targetTeams->map(fn (Team $team): array => [
                'id' => $team->id,
                'code' => $team->code,
                'name' => $team->name,
            ])->values()->all(),
            'recipients' => $eligibleUsers
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'teams' => $user->teams->map(fn (Team $team): array => [
                        'id' => $team->id,
                        'code' => $team->code,
                        'name' => $team->name,
                    ])->values(),
                ])
                ->values()
                ->all(),
            'blocked_reason' => $eligibleUsers->isEmpty() ? implode(' ', array_unique($blockedReasons)) : null,
        ];
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
                        'NDT Alarmering',
                        $this->notificationBody($dispatch),
                        $this->notificationData($dispatch),
                        (string) $dispatch->id,
                    )->onQueue('push');
                }
            }

            $this->auditService->record('dispatch.sent', $dispatch, $actor);
            $this->broadcastDispatchChange($dispatch, 'sent');

            return $dispatch->refresh()->load(['recipients']);
        });
    }

    public function respond(DispatchRequest $dispatch, User $actor, string $response, ?string $note): DispatchRecipient
    {
        $recipient = $dispatch->recipients()->where('user_id', $actor->id)->firstOrFail();
        $recipient->update(['response_status' => $response, 'response_note' => $note, 'responded_at' => now()]);
        $this->auditService->record('dispatch.responded', $dispatch, $actor, ['response' => $response]);
        $this->broadcastDispatchChange($dispatch->refresh(), 'responded');

        return $recipient;
    }

    public function overrideRecipientResponse(DispatchRequest $dispatch, DispatchRecipient $recipient, User $actor, string $response, ?string $note): DispatchRecipient
    {
        if ($recipient->dispatch_request_id !== $dispatch->id) {
            throw ValidationException::withMessages(['recipient' => ['Ontvanger hoort niet bij deze alarmering.']]);
        }

        $recipient->update([
            'response_status' => $response,
            'response_note' => $note,
            'responded_at' => $response === 'pending' ? null : now(),
        ]);

        $this->auditService->record('dispatch.recipient_response_overridden', $dispatch, $actor, [
            'recipient_id' => $recipient->id,
            'user_id' => $recipient->user_id,
            'response' => $response,
        ]);
        $this->broadcastDispatchChange($dispatch->refresh(), 'recipient_response_overridden');

        return $recipient->refresh()->load('user');
    }

    /**
     * @return array{queued_tokens: int, recipient_users: int}
     */
    public function sendAdditionalInfo(DispatchRequest $dispatch, User $actor, string $message): array
    {
        $dispatch->load([
            'incident',
            'recipients.user.fcmTokens',
            'recipients.user.statuses' => fn ($statuses) => $statuses->latestPerUser(),
        ]);
        $dispatchMessage = $dispatch->messages()->create([
            'sent_by' => $actor->id,
            'body' => $message,
            'created_at' => now(),
        ]);
        $recipients = $dispatch->recipients
            ->filter(fn (DispatchRecipient $recipient): bool => $recipient->response_status === 'accepted'
                || in_array($recipient->user?->statuses->first()?->status, ['en_route', 'on_scene'], true))
            ->values();

        $queuedTokens = 0;
        foreach ($recipients as $recipient) {
            foreach ($recipient->user?->fcmTokens->where('is_active', true) ?? [] as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'dispatch_update',
                    'D.I.S aanvullende info',
                    $message,
                    [
                        'type' => 'dispatch_update',
                        'dispatch_id' => (string) $dispatch->id,
                        'incident_id' => (string) $dispatch->incident_id,
                    ],
                    (string) $dispatch->id,
                )->onQueue('push');
                $queuedTokens++;
            }
        }

        $this->auditService->record('dispatch.additional_info_sent', $dispatch, $actor, [
            'message_id' => $dispatchMessage->id,
            'recipient_users' => $recipients->count(),
            'queued_tokens' => $queuedTokens,
        ]);
        $this->broadcastDispatchChange($dispatch->refresh(), 'additional_info_sent');

        return [
            'queued_tokens' => $queuedTokens,
            'recipient_users' => $recipients->count(),
        ];
    }

    public function escalate(DispatchRequest $dispatch, User $actor): DispatchRequest
    {
        if ($dispatch->status === 'cancelled') {
            throw ValidationException::withMessages(['dispatch' => ['Een geannuleerde alarmering kan niet worden opgeschaald.']]);
        }

        $dispatch->update(['status' => 'escalated']);
        $this->auditService->record('dispatch.escalated', $dispatch, $actor);
        $this->broadcastDispatchChange($dispatch->refresh(), 'escalated');

        return $dispatch->load(['incident', 'targetTeam', 'recipients.user']);
    }

    public function reAlert(DispatchRequest $dispatch, User $actor): DispatchRequest
    {
        if ($dispatch->status === 'cancelled') {
            throw ValidationException::withMessages(['dispatch' => ['Een geannuleerde alarmering kan niet opnieuw worden verstuurd.']]);
        }

        $dispatch->load(['incident', 'recipients.user.fcmTokens']);
        $queuedTokens = 0;

        foreach ($dispatch->recipients as $recipient) {
            if ($recipient->response_status !== 'pending') {
                continue;
            }

            foreach ($recipient->user?->fcmTokens->where('is_active', true) ?? [] as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'dispatch_request',
                    'NDT Heralarmering',
                    $this->notificationBody($dispatch, 'Reactie vereist'),
                    $this->notificationData($dispatch),
                    (string) $dispatch->id,
                )->onQueue('push');
                $queuedTokens++;
            }
        }

        $dispatch->recipients()->where('response_status', 'pending')->update(['notified_at' => now()]);
        $this->auditService->record('dispatch.realerted', $dispatch, $actor, ['queued_tokens' => $queuedTokens]);
        $this->broadcastDispatchChange($dispatch->refresh(), 'realerted');

        return $dispatch->load(['incident', 'targetTeam', 'recipients.user']);
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
     * @param array<string, mixed> $data
     * @return Collection<int, Team>
     */
    private function targetTeams(Incident $incident, array $data): Collection
    {
        if (isset($data['target_team_id']) || isset($data['team_code'])) {
            $targetTeam = $this->targetTeam($incident, $data);

            return $targetTeam === null ? collect() : collect([$targetTeam]);
        }

        $incident->loadMissing('teams');
        if ($incident->teams->isNotEmpty()) {
            return $incident->teams->values();
        }

        $targetTeam = $this->targetTeam($incident, []);

        return $targetTeam === null ? collect() : collect([$targetTeam]);
    }

    /**
     * @return array{users: Collection<int, User>, message: string}
     */
    private function eligibleUsers(Team $targetTeam): array
    {
        $targetTeam->loadMissing('requiredCertifications');
        $requiredCertificationIds = $targetTeam->requiredCertifications->pluck('id');
        if ($requiredCertificationIds->isEmpty()) {
            $requiredCertificationIds = Certification::query()
                ->where('is_required_for_dispatch', true)
                ->pluck('id');
        }
        $teamCodes = $this->expandTeamCodes($targetTeam);

        $teamUsers = User::query()
            ->with([
                'certifications',
                'fcmTokens' => fn ($tokens) => $tokens->where('is_active', true),
                'statuses' => fn ($statuses) => $statuses->latestPerUser(),
                'teams',
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

    public function broadcastDispatchChange(DispatchRequest $dispatch, string $action): void
    {
        try {
            DispatchChanged::dispatch($dispatch, $action);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function defaultDispatchMessage(Incident $incident): string
    {
        $parts = [
            $incident->reference,
            $incident->title,
            $incident->location_label,
        ];

        return implode(' - ', array_values(array_filter($parts, fn (?string $part): bool => filled($part))));
    }

    /**
     * @return array<string, string>
     */
    private function notificationData(DispatchRequest $dispatch): array
    {
        $incident = $dispatch->incident;

        return [
            'type' => 'dispatch_request',
            'action_mode' => 'attendance',
            'is_test' => $incident?->is_test ? 'true' : 'false',
            'dispatch_id' => (string) $dispatch->id,
            'incident_id' => (string) $dispatch->incident_id,
            'incident_reference' => (string) ($incident?->reference ?? ''),
            'incident_title' => (string) ($incident?->title ?? ''),
            'incident_location' => (string) ($incident?->location_label ?? ''),
            'dispatch_message' => (string) $dispatch->message,
            'priority' => (string) $dispatch->priority,
        ];
    }

    private function notificationBody(DispatchRequest $dispatch, ?string $prefix = null): string
    {
        $incident = $dispatch->incident;
        $parts = array_values(array_filter([
            $prefix,
            $dispatch->message,
            $incident?->reference,
            $incident?->title,
            $incident?->location_label,
        ], fn (?string $part): bool => filled($part)));

        return implode(' - ', $parts);
    }
}
