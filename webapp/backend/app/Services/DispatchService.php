<?php

namespace App\Services;

use App\Events\DispatchChanged;
use App\Events\IncidentChanged;
use App\Jobs\SendFcmNotification;
use App\Models\AvailabilityStatus;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DispatchService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly AvailabilityScheduleService $availabilityScheduleService,
    ) {}

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

            $eligibility = $this->selectDispatchUsers($incident, $targetTeam, $data, (bool) ($data['include_unavailable'] ?? false));
            $eligible = $eligibility['users'];
            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages(['team_code' => [$eligibility['message']]]);
            }

            $dispatch = DispatchRequest::query()->create([
                'incident_id' => $incident->id,
                'requested_by' => $actor->id,
                'requested_by_name' => $actor->name,
                'requested_by_email' => $actor->email,
                'target_team_id' => $targetTeam->id,
                'status' => 'draft',
                'priority' => $data['priority'],
                'message' => $data['message'],
                'includes_unavailable_recipients' => (bool) ($data['include_unavailable'] ?? false),
            ]);

            foreach ($eligible as $user) {
                DispatchRecipient::query()->create([
                    'dispatch_request_id' => $dispatch->id,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'response_status' => 'pending',
                ]);
            }

            $this->auditService->record('dispatch.created', $dispatch, $actor, ['recipient_count' => $eligible->count()]);
            $this->broadcastDispatchChange($dispatch, 'created');

            return $dispatch->load(['incident', 'recipients']);
        });
    }

    public function createAndSendForIncidentActivation(Incident $incident, User $actor, ?string $message = null, array $options = []): ?DispatchRequest
    {
        $existingDrafts = $incident->dispatchRequests()
            ->where('status', 'draft')
            ->with(['incident', 'recipients.user.fcmTokens'])
            ->get();

        if ($existingDrafts->isNotEmpty()) {
            $sentDispatch = null;
            foreach ($existingDrafts as $draft) {
                $sentDispatch ??= $this->markSent($draft, $actor);
            }

            return $sentDispatch;
        }

        if ($incident->dispatchRequests()->where('status', 'sent')->exists()) {
            return null;
        }

        $dispatch = null;
        $remaining = $this->requestedRecipientCount($options);
        foreach ($this->targetTeams($incident, []) as $targetTeam) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $created = $this->create($incident, [
                'priority' => $incident->priority === 'low' ? 'normal' : $incident->priority,
                'message' => $message ?: $this->defaultDispatchMessage($incident),
                'target_team_id' => $targetTeam->id,
                'dispatch_recipient_count' => $remaining,
            ] + $options, $actor);

            $sent = $this->markSent($created, $actor);
            $dispatch ??= $sent;
            if ($remaining !== null) {
                $remaining -= $sent->recipients()->count();
            }
        }

        return $dispatch;
    }

    /**
     * @return array{queued_tokens: int, recipient_users: int}
     */
    public function sendPreannouncementForIncidentActivation(Incident $incident, User $actor, ?string $message = null, array $options = []): array
    {
        $place = $this->placeNameFromLocation($incident->location_label);
        $tokens = $this->pushTemplateTokens($incident, ['place' => $place ?? '']);
        $notificationTitle = $this->pushTemplate('preannouncement_title', 'D.I.S vooraankondiging', $tokens);
        $notificationBody = $this->pushTemplate(
            'preannouncement_body',
            $place === null ? 'Ben je beschikbaar voor een melding?' : 'Ben je beschikbaar voor een melding in {{place}}?',
            $tokens,
        );

        $queuedTokens = 0;
        $recipientCount = 0;
        $dispatches = collect();
        $remaining = $this->requestedRecipientCount($options);
        foreach ($this->targetTeams($incident, []) as $targetTeam) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $dispatch = $incident->dispatchRequests()
                ->where('status', 'draft')
                ->where('target_team_id', $targetTeam->id)
                ->first();

            if ($dispatch === null) {
                $dispatch = $this->create($incident, [
                    'priority' => $incident->priority === 'low' ? 'normal' : $incident->priority,
                    'message' => $notificationBody,
                    'target_team_id' => $targetTeam->id,
                    'dispatch_recipient_count' => $remaining,
                ] + $options, $actor);
            }

            $dispatch->load(['recipients.user.fcmTokens']);
            $dispatches->push($dispatch);
            $recipientCount += $dispatch->recipients->count();
            if ($remaining !== null) {
                $remaining -= $dispatch->recipients->count();
            }

            foreach ($dispatch->recipients as $recipient) {
                foreach ($recipient->user?->fcmTokens->where('is_active', true) ?? [] as $token) {
                    SendFcmNotification::dispatch(
                        (string) $token->id,
                        'manual_admin',
                        $notificationTitle,
                        $notificationBody,
                        [
                            'type' => 'manual_admin',
                            'action_mode' => 'availability',
                            'incident_id' => (string) $incident->id,
                            'dispatch_id' => (string) $dispatch->id,
                        ],
                        (string) $dispatch->id,
                    )->onQueue('push');
                    $queuedTokens++;
                }
            }

            $dispatch->recipients()->whereNull('notified_at')->update(['notified_at' => now()]);
            $this->broadcastDispatchChange($dispatch->refresh(), 'preannouncement_sent');
        }

        $this->auditService->record('incidents.preannouncement_sent', $incident, $actor, [
            'dispatch_ids' => $dispatches->pluck('id')->values()->all(),
            'recipient_users' => $recipientCount,
            'queued_tokens' => $queuedTokens,
        ]);

        return [
            'queued_tokens' => $queuedTokens,
            'recipient_users' => $recipientCount,
        ];
    }

    /**
     * @return array{queued_tokens: int, recipient_users: int}
     */
    public function sendCancellationForActiveIncident(Incident $incident, User $actor): array
    {
        $incident->load([
            'dispatchRequests.recipients.user.fcmTokens',
        ]);

        $recipients = $incident->dispatchRequests
            ->where('status', 'draft')
            ->flatMap(fn (DispatchRequest $dispatch): Collection => $dispatch->recipients)
            ->unique('user_id')
            ->values();

        if ($recipients->isEmpty()) {
            foreach ($this->targetTeams($incident, []) as $targetTeam) {
                $recipients = $recipients->merge(
                    $this->eligibleUsers($targetTeam)['users']
                        ->map(fn (User $user): object => (object) ['user' => $user, 'user_id' => $user->id]),
                );
            }
            $recipients = $recipients->unique('user_id')->values();
        }

        $place = $this->placeNameFromLocation($incident->location_label);
        $tokens = $this->pushTemplateTokens($incident, ['place' => $place ?? '']);
        $title = $this->pushTemplate('cancellation_title', 'D.I.S geannuleerd', $tokens);
        $body = $this->pushTemplate(
            'cancellation_body',
            $place === null ? 'De vooraankondiging is geannuleerd.' : 'De vooraankondiging in {{place}} is geannuleerd.',
            $tokens,
        );

        $queuedTokens = 0;
        foreach ($recipients as $recipient) {
            foreach ($recipient->user?->fcmTokens->where('is_active', true) ?? [] as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'incident_cancelled',
                    $title,
                    $body,
                    [
                        'type' => 'incident_cancelled',
                    ],
                )->onQueue('push');
                $queuedTokens++;
            }
        }

        $this->auditService->record('incidents.active_cancelled_notification_sent', $incident, $actor, [
            'recipient_users' => $recipients->count(),
            'queued_tokens' => $queuedTokens,
        ]);

        return [
            'queued_tokens' => $queuedTokens,
            'recipient_users' => $recipients->count(),
        ];
    }

    /**
     * @return array{team: array<string, mixed>|null, recipients: list<array<string, mixed>>, blocked_reason: string|null}
     */
    public function previewForIncident(Incident $incident, array $options = []): array
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
            $eligibility = $this->eligibleUsers($targetTeam, (bool) ($options['include_unavailable'] ?? false));
            $eligibleUsers = $eligibleUsers->merge($eligibility['users']);
            if ($eligibility['users']->isEmpty()) {
                $blockedReasons[] = $eligibility['message'];
            }
        }
        $eligibleUsers = $this->rankUsersByIncidentEta($incident, $eligibleUsers->unique('id')->values());
        $requestedCount = $this->requestedRecipientCount($options);
        if ($requestedCount !== null) {
            $eligibleUsers = $eligibleUsers->take($requestedCount)->values();
        }
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
                    'home_city' => $user->home_city,
                    'eta_minutes' => $this->estimatedEtaMinutesForUser($incident, $user),
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
        $wasPreannouncement = $dispatch->status === 'draft';
        $dispatch->update(['status' => 'sent', 'sent_at' => now()]);
        if ($wasPreannouncement) {
            $dispatch->recipients()
                ->where('response_status', 'accepted')
                ->update([
                    'response_status' => 'pending',
                    'response_note' => 'Was beschikbaar bij de vooraankondiging; wacht op reactie op de alarmering.',
                    'responded_at' => null,
                ]);
        }
        $dispatch->recipients()->whereNull('notified_at')->update(['notified_at' => now()]);
        $dispatch->load([
            'incident',
            'recipients.user.fcmTokens',
            'recipients.user.statuses' => fn ($statuses) => $statuses->latestPerUser(),
        ]);
        $dispatchTitle = $this->pushTemplate('dispatch_title', 'NDT Alarmering', $this->pushTemplateTokens($dispatch->incident));

        foreach ($dispatch->recipients as $recipient) {
                if (! in_array($recipient->response_status, ['pending', 'accepted'], true)) {
                    continue;
                }

                $user = $recipient->user;
                $unavailableEscalation = $dispatch->includes_unavailable_recipients
                    && $user !== null
                    && ! $this->isOperationallyAvailable($user);
                $notificationTitle = $unavailableEscalation
                    ? $this->pushTemplate(
                        'dispatch_unavailable_escalation_title',
                        'NDT urgente opschaling',
                        $this->pushTemplateTokens($dispatch->incident),
                    )
                    : $dispatchTitle;
                $notificationBody = $unavailableEscalation && $user !== null
                    ? $this->unavailableEscalationNotificationBody($dispatch, $user)
                    : $this->notificationBody($dispatch);
                $notificationData = $this->notificationData($dispatch) + [
                    'unavailable_escalation' => $unavailableEscalation ? 'true' : 'false',
                ];

                foreach ($recipient->user?->fcmTokens->where('is_active', true) ?? [] as $token) {
                    SendFcmNotification::dispatch(
                        (string) $token->id,
                        'dispatch_request',
                        $notificationTitle,
                        $notificationBody,
                        $notificationData,
                        (string) $dispatch->id,
                    )->onQueue('push');
                }
            }

            if ($dispatch->incident !== null) {
                $this->transitionIncidentStatus(
                    $dispatch->incident,
                    $actor,
                    'dispatching',
                    'Automatisch naar alarmeren gezet nadat de alarmering is verstuurd.',
                );
            }

            $this->auditService->record('dispatch.sent', $dispatch, $actor);
            $this->broadcastDispatchChange($dispatch, 'sent');

            return $dispatch->refresh()->load(['recipients']);
        });
    }

    public function respond(DispatchRequest $dispatch, User $actor, string $response, ?string $note): DispatchRecipient
    {
        $isPreannouncement = $dispatch->status === 'draft';
        $recipient = $dispatch->recipients()->where('user_id', $actor->id)->firstOrFail();
        $recipient->update([
            'response_status' => $response,
            'response_note' => $note ?? $this->defaultResponseNote($response, $isPreannouncement),
            'responded_at' => now(),
        ]);
        $this->auditService->record('dispatch.responded', $dispatch, $actor, [
            'response' => $response,
            'action_mode' => $isPreannouncement ? 'availability' : 'attendance',
        ]);
        $this->syncResponseToUserDevices($dispatch, $actor, $response);
        $this->broadcastDispatchChange($dispatch->refresh(), 'responded');
        if (! $isPreannouncement && $response === 'accepted') {
            $this->transitionIncidentToInProgressWhenEveryoneOnScene($dispatch->refresh(), $actor);
        }

        return $recipient;
    }

    private function defaultResponseNote(string $response, bool $isPreannouncement): ?string
    {
        if (! $isPreannouncement) {
            return null;
        }

        return match ($response) {
            'accepted' => 'Beschikbaar voor eventuele inzet.',
            'declined' => 'Niet beschikbaar voor eventuele inzet.',
            default => null,
        };
    }

    private function syncResponseToUserDevices(DispatchRequest $dispatch, User $actor, string $response): void
    {
        $actionMode = $dispatch->status === 'draft' ? 'availability' : 'attendance';
        $title = $actionMode === 'availability' ? 'D.I.S beschikbaarheid bijgewerkt' : 'D.I.S alarmering bijgewerkt';
        $body = $actionMode === 'availability' ? 'Je beschikbaarheid is verwerkt.' : 'Je reactie is verwerkt.';

        foreach ($actor->fcmTokens()->where('is_active', true)->get() as $token) {
            SendFcmNotification::dispatch(
                (string) $token->id,
                'dispatch_response_sync',
                $title,
                $body,
                [
                    'type' => 'dispatch_response_sync',
                    'action_mode' => $actionMode,
                    'dispatch_id' => (string) $dispatch->id,
                    'incident_id' => (string) $dispatch->incident_id,
                    'response' => $response,
                ],
                (string) $dispatch->id,
            )->onQueue('push');
        }
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
        if ($dispatch->status !== 'draft' && $response === 'accepted') {
            $this->transitionIncidentToInProgressWhenEveryoneOnScene($dispatch->refresh(), $actor);
        }

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
            'sent_by_name' => $actor->name,
            'sent_by_email' => $actor->email,
            'body' => $message,
            'created_at' => now(),
        ]);
        $recipients = $dispatch->recipients
            ->filter(fn (DispatchRecipient $recipient): bool => $recipient->response_status === 'accepted'
                || in_array($recipient->user?->statuses->first()?->status, ['en_route', 'on_scene'], true))
            ->values();

        $queuedTokens = 0;
        $tokens = $this->pushTemplateTokens($dispatch->incident, ['message' => $message]);
        $title = $this->pushTemplate('additional_info_title', 'D.I.S aanvullende info', $tokens);
        $body = $this->pushTemplate('additional_info_body', '{{message}}', $tokens);
        foreach ($recipients as $recipient) {
            foreach ($recipient->user?->fcmTokens->where('is_active', true) ?? [] as $token) {
                    SendFcmNotification::dispatch(
                        (string) $token->id,
                        'dispatch_update',
                        $title,
                        $body,
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

    /**
     * @param array<int, string> $teamIds
     */
    public function escalate(DispatchRequest $dispatch, User $actor, array $teamIds = [], bool $includeUnavailable = false): DispatchRequest
    {
        if ($dispatch->status === 'cancelled') {
            throw ValidationException::withMessages(['dispatch' => ['Een geannuleerde alarmering kan niet worden opgeschaald.']]);
        }

        $dispatch->loadMissing(['incident.dispatchRequests', 'incident.teams']);
        if ($includeUnavailable && ! in_array($dispatch->incident?->priority, ['high', 'critical'], true)) {
            throw ValidationException::withMessages([
                'include_unavailable' => ['Niet-beschikbare teamleden mogen alleen bij urgente incidenten worden gealarmeerd.'],
            ]);
        }

        $newTeams = $this->teamsForEscalation($dispatch, $teamIds);
        $eligibility = $newTeams->mapWithKeys(fn (Team $team): array => [$team->id => $this->eligibleUsers($team, $includeUnavailable)]);
        $blocked = $eligibility->filter(fn (array $result): bool => $result['users']->isEmpty());

        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'team_ids' => $blocked->map(fn (array $result): string => $result['message'])->values()->all(),
            ]);
        }

        return DB::transaction(function () use ($dispatch, $actor, $newTeams, $includeUnavailable): DispatchRequest {
            $incident = $dispatch->incident;
            if ($incident !== null && $newTeams->isNotEmpty()) {
                $incident->teams()->syncWithoutDetaching($newTeams->pluck('id')->all());
                $incident->forceFill(['team_id' => $incident->team_id ?? $newTeams->first()?->id])->save();

                foreach ($newTeams as $team) {
                    $created = $this->create($incident->refresh(), [
                        'priority' => $dispatch->priority,
                        'message' => $dispatch->message ?: $this->defaultDispatchMessage($incident),
                        'target_team_id' => $team->id,
                        'include_unavailable' => $includeUnavailable,
                    ], $actor);

                    $this->markSent($created, $actor);
                }
            }

            $dispatch->update(['status' => 'escalated']);
            $this->auditService->record('dispatch.escalated', $dispatch, $actor, [
                'added_team_ids' => $newTeams->pluck('id')->values()->all(),
                'added_team_codes' => $newTeams->pluck('code')->values()->all(),
                'include_unavailable' => $includeUnavailable,
            ]);
            $this->broadcastDispatchChange($dispatch->refresh(), 'escalated');

            return $dispatch->load(['incident', 'targetTeam', 'recipients.user']);
        });
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
     * @param array<int, string> $teamIds
     * @return Collection<int, Team>
     */
    private function teamsForEscalation(DispatchRequest $dispatch, array $teamIds): Collection
    {
        $teamIds = array_values(array_unique(array_filter($teamIds, fn (mixed $teamId): bool => is_string($teamId) && $teamId !== '')));
        if ($teamIds === []) {
            return collect();
        }

        $incident = $dispatch->incident;
        if ($incident === null) {
            throw ValidationException::withMessages(['dispatch' => ['Deze alarmering is niet gekoppeld aan een incident.']]);
        }

        $alreadyDispatchedTeamIds = $incident->dispatchRequests
            ->filter(fn (DispatchRequest $existing): bool => $existing->status !== 'cancelled' && $existing->target_team_id !== null)
            ->pluck('target_team_id')
            ->unique()
            ->values();

        $teams = Team::query()
            ->whereIn('id', $teamIds)
            ->where('is_operational', true)
            ->get()
            ->filter(fn (Team $team): bool => ! $alreadyDispatchedTeamIds->contains($team->id))
            ->values();

        if ($teams->isEmpty()) {
            throw ValidationException::withMessages(['team_ids' => ['Kies minimaal een operationeel team dat nog niet voor dit incident is gealarmeerd.']]);
        }

        return $teams;
    }

    /**
     * @return array{users: Collection<int, User>, message: string}
     */
    private function selectDispatchUsers(Incident $incident, Team $targetTeam, array $data, bool $includeUnavailable = false): array
    {
        $eligibility = $this->eligibleUsers($targetTeam, $includeUnavailable);
        $users = $this->rankUsersByIncidentEta($incident, $eligibility['users']);
        $requestedCount = $this->requestedRecipientCount($data);
        if ($requestedCount !== null) {
            $users = $users->take($requestedCount)->values();
        }

        return [
            'users' => $users,
            'message' => $eligibility['message'],
        ];
    }

    /**
     * @return array{users: Collection<int, User>, message: string}
     */
    private function eligibleUsers(Team $targetTeam, bool $includeUnavailable = false): array
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
                'roles',
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
        $availableUsers = $includeUnavailable
            ? $tokenUsers
            : $tokenUsers
                ->filter(fn (User $user): bool => $this->isOperationallyAvailable($user))
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

    private function isOperationallyAvailable(User $user): bool
    {
        $latestStatus = $user->statuses->first();
        if ($latestStatus !== null && $latestStatus->is_available !== true) {
            return false;
        }

        return $this->availabilityScheduleService->isAvailable($user);
    }

    private function requestedRecipientCount(array $data): ?int
    {
        $count = $data['dispatch_recipient_count'] ?? null;
        if ($count === null || $count === '') {
            return null;
        }

        $count = (int) $count;

        return $count > 0 ? $count : null;
    }

    /**
     * @param Collection<int, User> $users
     * @return Collection<int, User>
     */
    private function rankUsersByIncidentEta(Incident $incident, Collection $users): Collection
    {
        return $users
            ->map(fn (User $user): array => [
                'user' => $user,
                'eta_minutes' => $this->estimatedEtaMinutesForUser($incident, $user),
                'distance_km' => $this->distanceKmForUser($incident, $user),
            ])
            ->sort(function (array $left, array $right): int {
                return (($left['eta_minutes'] ?? PHP_INT_MAX) <=> ($right['eta_minutes'] ?? PHP_INT_MAX))
                    ?: (($left['distance_km'] ?? PHP_INT_MAX) <=> ($right['distance_km'] ?? PHP_INT_MAX))
                    ?: strcmp($left['user']->name, $right['user']->name);
            })
            ->pluck('user')
            ->values();
    }

    private function estimatedEtaMinutesForUser(Incident $incident, User $user): ?int
    {
        $distanceKm = $this->distanceKmForUser($incident, $user);
        if ($distanceKm === null) {
            return null;
        }

        $speedKmh = max(1.0, (float) config('dis.dispatch.estimated_eta_speed_kmh', 60));
        $ringMinutes = max(1, (int) config('dis.dispatch.eta_ring_minutes', 15));
        $minutes = ($distanceKm / $speedKmh) * 60;

        return max($ringMinutes, (int) ceil($minutes / $ringMinutes) * $ringMinutes);
    }

    private function distanceKmForUser(Incident $incident, User $user): ?float
    {
        $incidentLatitude = $this->coordinate($incident->latitude, -90, 90);
        $incidentLongitude = $this->coordinate($incident->longitude, -180, 180);
        $homeLatitude = $this->coordinate($user->home_latitude, -90, 90);
        $homeLongitude = $this->coordinate($user->home_longitude, -180, 180);
        if ($incidentLatitude === null || $incidentLongitude === null || $homeLatitude === null || $homeLongitude === null) {
            return null;
        }

        return $this->distanceKm($homeLatitude, $homeLongitude, $incidentLatitude, $incidentLongitude);
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;
        if (! is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
            return null;
        }

        return $coordinate;
    }

    private function distanceKm(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadiusKm = 6371.0;
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
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

    private function transitionIncidentStatus(Incident $incident, User $actor, string $status, string $reason): void
    {
        $incident->refresh();
        if (in_array($incident->status, ['resolved', 'cancelled', $status], true)) {
            return;
        }

        if ($status === 'dispatching' && ! in_array($incident->status, ['draft', 'active'], true)) {
            return;
        }

        $previousStatus = $incident->status;
        $incident->forceFill(['status' => $status])->save();
        $incident->statusHistory()->create([
            'from_status' => $previousStatus,
            'to_status' => $status,
            'changed_by' => $actor->id,
            'changed_by_name' => $actor->name,
            'changed_by_email' => $actor->email,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        $this->auditService->record('incidents.status_auto_updated', $incident, $actor, [
            'from_status' => $previousStatus,
            'to_status' => $status,
        ], $reason);
        $this->broadcastIncidentChange($incident->refresh(), 'status_auto_updated');
    }

    private function transitionIncidentToInProgressWhenEveryoneOnScene(DispatchRequest $dispatch, User $actor): void
    {
        $dispatch->loadMissing(['incident.dispatchRequests.recipients']);
        $incident = $dispatch->incident;
        if ($incident === null || ! in_array($incident->status, ['active', 'dispatching'], true)) {
            return;
        }

        $acceptedUserIds = $incident->dispatchRequests
            ->whereIn('status', ['sent', 'escalated'])
            ->flatMap(fn (DispatchRequest $existingDispatch) => $existingDispatch->recipients)
            ->filter(fn (DispatchRecipient $recipient): bool => $recipient->response_status === 'accepted')
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($acceptedUserIds->isEmpty()) {
            return;
        }

        $latestStatuses = AvailabilityStatus::query()
            ->latestPerUser()
            ->whereIn('user_id', $acceptedUserIds->all())
            ->pluck('status', 'user_id');

        $everyoneOnScene = $acceptedUserIds
            ->every(fn (string $userId): bool => $latestStatuses->get($userId) === 'on_scene');

        if ($everyoneOnScene) {
            $this->transitionIncidentStatus(
                $incident,
                $actor,
                'in_progress',
                'Automatisch naar uitvoering gezet omdat alle geaccepteerde opkomers op locatie zijn.',
            );
        }
    }

    private function broadcastIncidentChange(Incident $incident, string $action): void
    {
        try {
            IncidentChanged::dispatch($incident, $action);
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

    private function placeNameFromLocation(?string $location): ?string
    {
        $value = trim((string) $location);
        if ($value === '') {
            return null;
        }

        $segments = array_values(array_filter(array_map('trim', preg_split('/[,;|-]/', $value) ?: [])));
        $place = $segments !== [] ? end($segments) : $value;
        if (! is_string($place) || $place === '') {
            return null;
        }

        $place = trim((string) preg_replace('/\b[1-9][0-9]{3}\s?[A-Z]{2}\b/i', '', $place));
        $place = trim((string) preg_replace('/\s+/', ' ', $place));

        return $place === '' ? null : $place;
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
        $message = trim((string) $dispatch->message);
        if ($message === '' && $dispatch->incident !== null) {
            $message = $this->defaultDispatchMessage($dispatch->incident);
        }

        if ($dispatch->incident !== null) {
            $message = $this->pushTemplate('dispatch_body', $message, $this->pushTemplateTokens($dispatch->incident, [
                'message' => $message,
            ]));
        }

        $prefix = trim((string) $prefix);
        if ($prefix === '') {
            return $message;
        }

        return $message === '' ? $prefix : "$prefix - $message";
    }

    private function unavailableEscalationNotificationBody(DispatchRequest $dispatch, User $user): string
    {
        $message = trim((string) $dispatch->message);
        if ($message === '' && $dispatch->incident !== null) {
            $message = $this->defaultDispatchMessage($dispatch->incident);
        }

        $tokens = $this->pushTemplateTokens($dispatch->incident, [
            'message' => $message,
            'reason' => 'Urgente opschaling: de coordinator heeft gekozen om ook niet-beschikbare teamleden te alarmeren.',
            'availability_reason' => $this->unavailableReason($user),
        ]);

        return $this->pushTemplate(
            'dispatch_unavailable_escalation_body',
            '{{reason}} {{availability_reason}} {{message}}',
            $tokens,
        );
    }

    private function unavailableReason(User $user): string
    {
        $latestStatus = $user->statuses->first();
        if ($latestStatus !== null && $latestStatus->is_available !== true) {
            return 'Je actuele status staat op '.match ($latestStatus->status) {
                'unavailable' => 'niet beschikbaar.',
                'resting' => 'rust.',
                'suspended' => 'geblokkeerd.',
                'assigned' => 'toegewezen.',
                'vacation' => 'vakantie.',
                default => $latestStatus->status.'.',
            };
        }

        $availability = $this->availabilityScheduleService->availabilityFor($user);
        if ($availability['is_available'] === false) {
            $source = match ($availability['source']) {
                'override' => 'een ingeplande uitzondering',
                'week_pattern' => 'je vaste weekpatroon',
                default => 'je beschikbaarheidsschema',
            };
            $note = trim((string) ($availability['note'] ?? ''));

            return $note === ''
                ? "Je staat niet beschikbaar volgens {$source}."
                : "Je staat niet beschikbaar volgens {$source}: {$note}.";
        }

        return 'Je stond niet beschikbaar op het moment van alarmeren.';
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function pushTemplateTokens(?Incident $incident, array $extra = []): array
    {
        $place = $extra['place'] ?? $this->placeNameFromLocation($incident?->location_label) ?? '';

        return array_merge([
            'reference' => (string) ($incident?->reference ?? ''),
            'title' => (string) ($incident?->title ?? ''),
            'location' => (string) ($incident?->location_label ?? ''),
            'place' => $place,
            'priority' => (string) ($incident?->priority ?? ''),
            'message' => '',
        ], $extra);
    }

    /**
     * @param array<string, string> $tokens
     */
    private function pushTemplate(string $name, string $default, array $tokens): string
    {
        $template = SystemSetting::string("push.template.{$name}", $default) ?? $default;
        $replacements = [];
        foreach ($tokens as $key => $value) {
            $replacements['{{'.$key.'}}'] = $value;
        }

        $rendered = trim(strtr($template, $replacements));
        if ($rendered !== '') {
            return $rendered;
        }

        return trim(strtr($default, $replacements));
    }
}
