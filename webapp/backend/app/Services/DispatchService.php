<?php

namespace App\Services;

use App\DTO\Routing\RouteEstimate;
use App\DTO\Routing\RoutePoint;
use App\DTO\Routing\RouteSource;
use App\Events\DispatchChanged;
use App\Events\IncidentChanged;
use App\Jobs\SendFcmNotification;
use App\Models\AvailabilityStatus;
use App\Models\Certification;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Routing\RoutingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DispatchService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly AvailabilityScheduleService $availabilityScheduleService,
        private readonly DispatchPushOutboxService $dispatchPushOutboxService,
        private readonly IncidentFormService $incidentFormService,
        private readonly LocationService $locationService,
        private readonly RoutingService $routingService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Incident $incident, array $data, User $actor): DispatchRequest
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $incident->refresh();
            if (in_array($incident->status, ['resolved', 'cancelled'], true)) {
                throw ValidationException::withMessages(['incident_id' => ['Cannot dispatch for a closed incident.']]);
            }

            $targetTeam = $this->targetTeam($incident, $data);
            if ($targetTeam === null) {
                throw ValidationException::withMessages(['team_code' => ['Het gekozen team bestaat niet.']]);
            }

            // Route-provider I/O is intentionally completed before opening
            // this transaction. The target is fingerprinted so a concurrent
            // coordinate change cannot commit a stale ETA ranking.
            $routeTarget = $this->incidentRouteFingerprint($incident);
            $eligibility = $this->selectDispatchUsers($incident, $targetTeam, $data, (bool) ($data['include_unavailable'] ?? false));
            if ($eligibility['users']->isEmpty()) {
                throw ValidationException::withMessages(['team_code' => [$eligibility['message']]]);
            }

            $created = DB::transaction(function () use ($incident, $data, $actor, $targetTeam, $eligibility, $routeTarget): ?DispatchRequest {
                $currentIncident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
                if (in_array($currentIncident->status, ['resolved', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['incident_id' => ['Cannot dispatch for a closed incident.']]);
                }
                if ($this->incidentRouteFingerprint($currentIncident) !== $routeTarget) {
                    return null;
                }

                $currentTargetTeam = Team::query()->find($targetTeam->id);
                if ($currentTargetTeam === null) {
                    throw ValidationException::withMessages(['team_code' => ['Het gekozen team bestaat niet.']]);
                }

                // The incident row is already locked. This serializes creators
                // even when no matching dispatch row exists yet, so two
                // concurrent activation requests cannot both pass this check.
                if (DispatchRequest::query()
                    ->where('incident_id', $currentIncident->id)
                    ->where('target_team_id', $currentTargetTeam->id)
                    ->where('status', '!=', 'cancelled')
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'target_team_id' => ['Voor dit incident bestaat al een actieve alarmering voor het gekozen team.'],
                    ]);
                }

                $revalidated = $this->revalidateDispatchUsers(
                    $currentIncident,
                    $currentTargetTeam,
                    $eligibility['ranked_users'],
                    $data,
                    (bool) ($data['include_unavailable'] ?? false),
                );
                $eligible = $revalidated['users'];
                if ($eligible->isEmpty()) {
                    throw ValidationException::withMessages(['team_code' => [$revalidated['message']]]);
                }

                $dispatch = DispatchRequest::query()->create([
                    'incident_id' => $currentIncident->id,
                    'requested_by' => $actor->id,
                    'requested_by_name' => $actor->name,
                    'requested_by_email' => $actor->email,
                    'target_team_id' => $currentTargetTeam->id,
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

            if ($created !== null) {
                return $created;
            }
        }

        throw ValidationException::withMessages([
            'incident_id' => ['De incidentlocatie wijzigde tijdens de selectie. Probeer de alarmering opnieuw.'],
        ]);
    }

    /**
     * @return array{dispatch: DispatchRequest|null, warnings: list<string>}
     */
    public function createAndSendForIncidentActivation(Incident $incident, User $actor, ?string $message = null, array $options = []): array
    {
        $existingDrafts = $incident->dispatchRequests()
            ->where('status', 'draft')
            ->with(['incident', 'recipients.user.fcmTokens' => fn ($tokens) => $this->onlineOperatorTokenQuery($tokens)])
            ->get();

        if ($existingDrafts->isNotEmpty()) {
            $sentDispatch = null;
            $warnings = [];
            foreach ($existingDrafts as $draft) {
                try {
                    $sent = $this->markSent($draft, $actor);
                    $sentDispatch ??= $sent;
                } catch (ValidationException $exception) {
                    $warnings = array_merge($warnings, $this->validationMessages($exception));
                }
            }

            if ($sentDispatch === null) {
                throw ValidationException::withMessages([
                    'dispatch' => $warnings !== [] ? $warnings : ['Er zijn geen alarmeerbare gebruikers beschikbaar voor deze alarmering.'],
                ]);
            }

            return ['dispatch' => $sentDispatch, 'warnings' => array_values(array_unique($warnings))];
        }

        if ($incident->dispatchRequests()->where('status', 'sent')->exists()) {
            return ['dispatch' => null, 'warnings' => []];
        }

        $dispatch = null;
        $warnings = [];
        $remaining = $this->requestedRecipientCount($options);
        foreach ($this->targetTeams($incident, []) as $targetTeam) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            try {
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
            } catch (ValidationException $exception) {
                $warnings = array_merge($warnings, $this->validationMessages($exception));
            }
        }

        if ($dispatch === null) {
            throw ValidationException::withMessages([
                'dispatch' => $warnings !== [] ? $warnings : ['Er zijn geen alarmeerbare gebruikers beschikbaar voor deze alarmering.'],
            ]);
        }

        return ['dispatch' => $dispatch, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @return array{queued_tokens: int, recipient_users: int, warnings: list<string>}
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
        $warnings = [];
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
                try {
                    $dispatch = $this->create($incident, [
                        'priority' => $incident->priority === 'low' ? 'normal' : $incident->priority,
                        'message' => $message ?: $this->defaultDispatchMessage($incident),
                        'target_team_id' => $targetTeam->id,
                        'dispatch_recipient_count' => $remaining,
                    ] + $options, $actor);
                } catch (ValidationException $exception) {
                    $warnings = array_merge($warnings, $this->validationMessages($exception));

                    continue;
                }
            }

            $dispatch->load(['recipients.user.fcmTokens' => fn ($tokens) => $this->onlineOperatorTokenQuery($tokens)]);
            $dispatches->push($dispatch);
            $recipientCount += $dispatch->recipients->count();
            if ($remaining !== null) {
                $remaining -= $dispatch->recipients->count();
            }

            foreach ($dispatch->recipients as $recipient) {
                foreach ($recipient->user?->fcmTokens ?? [] as $token) {
                    $this->dispatchPushOutboxService->store(
                        dispatchRequestId: (string) $dispatch->id,
                        fcmTokenId: (string) $token->id,
                        messageType: 'incident_preannouncement',
                        title: $notificationTitle,
                        body: $notificationBody,
                        data: [
                            // Keep the established mobile wire contract for
                            // every still-supported Android and iOS build. The
                            // internal message type above identifies the phase
                            // for queue policy and diagnostics.
                            'type' => 'dispatch_update',
                            'action_mode' => 'availability',
                            'incident_id' => (string) $incident->id,
                            'dispatch_id' => (string) $dispatch->id,
                        ],
                    );
                    $queuedTokens++;
                }
            }

            $dispatch->recipients()->whereNull('notified_at')->update(['notified_at' => now()]);
            $this->broadcastDispatchChange($dispatch->refresh(), 'preannouncement_sent');
            $this->flushDispatchPushOutboxAfterCommit((string) $dispatch->id);
        }

        if ($recipientCount === 0) {
            throw ValidationException::withMessages([
                'dispatch' => $warnings !== [] ? $warnings : ['Er zijn geen alarmeerbare gebruikers beschikbaar voor deze vooraankondiging.'],
            ]);
        }

        $this->auditService->record('incidents.preannouncement_sent', $incident, $actor, [
            'dispatch_ids' => $dispatches->pluck('id')->values()->all(),
            'recipient_users' => $recipientCount,
            'queued_tokens' => $queuedTokens,
            'warnings' => array_values(array_unique($warnings)),
        ]);

        return [
            'queued_tokens' => $queuedTokens,
            'recipient_users' => $recipientCount,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array{queued_tokens: int, recipient_users: int}
     */
    public function sendCancellationForActiveIncident(Incident $incident, User $actor): array
    {
        $incident->load([
            'dispatchRequests.recipients.user.fcmTokens' => fn ($tokens) => $this->onlineOperatorTokenQuery($tokens),
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
            foreach ($recipient->user?->fcmTokens ?? [] as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'incident_cancelled',
                    $title,
                    $body,
                    [
                        'type' => 'incident_cancelled',
                        'incident_id' => (string) $incident->id,
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
     * @return array{team: array<string, mixed>|null, recipients: list<array<string, mixed>>, blocked_reason: string|null, warnings: list<string>}
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
                'warnings' => [],
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
        $eligibleUsers = $eligibleUsers->unique('id')->values();
        $routeEstimates = $this->routeEstimatesForUsers($incident, $eligibleUsers);
        $eligibleUsers = $this->rankUsersByIncidentEta($eligibleUsers, $routeEstimates);
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
                ->map(function (User $user) use ($routeEstimates): array {
                    $estimate = $routeEstimates[(string) $user->id] ?? RouteEstimate::unknown();

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'home_city' => $user->home_city,
                        'eta_minutes' => $this->etaRingMinutes($estimate),
                        'eta_source' => $estimate->source->value,
                        'teams' => $user->teams->map(fn (Team $team): array => [
                            'id' => $team->id,
                            'code' => $team->code,
                            'name' => $team->name,
                        ])->values(),
                    ];
                })
                ->values()
                ->all(),
            'blocked_reason' => $eligibleUsers->isEmpty() ? implode(' ', array_unique($blockedReasons)) : null,
            'warnings' => $eligibleUsers->isEmpty() ? [] : array_values(array_unique($blockedReasons)),
        ];
    }

    public function markSent(DispatchRequest $dispatch, User $actor): DispatchRequest
    {
        $dispatch->refresh();
        if ($dispatch->status === 'sent') {
            $this->flushDispatchPushOutboxAfterCommit((string) $dispatch->id);

            return $dispatch->load(['incident', 'targetTeam', 'recipients']);
        }
        if ($dispatch->status !== 'draft') {
            throw ValidationException::withMessages([
                'dispatch' => ['Alleen een conceptalarmering kan worden verstuurd.'],
            ]);
        }

        // Routing stays outside this transaction. The destination fingerprint
        // is checked again under the incident lock; one concurrent location
        // change causes a fresh selection instead of using a stale ranking.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $plan = $this->prepareSendCandidatePlan($dispatch);
            $dispatchMetadata = DispatchRequest::query()
                ->select(['id', 'incident_id'])
                ->find($dispatch->id);
            if ($dispatchMetadata === null) {
                throw ValidationException::withMessages([
                    'dispatch' => ['Deze alarmering bestaat niet meer.'],
                ]);
            }
            $dispatchIncidentId = (string) $dispatchMetadata->incident_id;

            $result = DB::transaction(function () use ($dispatch, $actor, $plan, $dispatchIncidentId): ?array {
                // Every operational write uses the same parent-to-child lock
                // order: incident, dispatch, recipient/outbox. This avoids the
                // former deadlock with incident activation holding the incident
                // row while a direct send held the dispatch row.
                $incident = Incident::query()->lockForUpdate()->find($dispatchIncidentId);
                if ($incident === null) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Het incident van deze alarmering bestaat niet meer.'],
                    ]);
                }
                $currentDispatch = DispatchRequest::query()->lockForUpdate()->find($dispatch->id);
                if ($currentDispatch === null) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Deze alarmering bestaat niet meer.'],
                    ]);
                }
                if ((string) $currentDispatch->incident_id !== $dispatchIncidentId) {
                    return null;
                }
                if ($currentDispatch->status === 'sent') {
                    return [
                        'dispatch' => $currentDispatch->load(['incident', 'targetTeam', 'recipients']),
                    ];
                }
                if ($currentDispatch->status !== 'draft') {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Alleen een conceptalarmering kan worden verstuurd.'],
                    ]);
                }

                if (in_array($incident->status, ['resolved', 'cancelled'], true)) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Voor een gesloten incident kan geen alarmering worden verstuurd.'],
                    ]);
                }
                if ($this->incidentRouteFingerprint($incident) !== $plan['route_target']) {
                    return null;
                }

                $targetTeam = Team::query()->find($currentDispatch->target_team_id);
                if ($targetTeam === null) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Het team van deze alarmering bestaat niet meer.'],
                    ]);
                }

                $lockedRecipients = $currentDispatch->recipients()->lockForUpdate()->get();
                $requestedCount = $lockedRecipients->count();
                if ($requestedCount === 0) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Deze alarmering heeft geen ontvangers.'],
                    ]);
                }
                $wasPreannouncement = $lockedRecipients->contains(
                    fn (DispatchRecipient $recipient): bool => $recipient->notified_at !== null,
                );
                $rankedCandidates = $this->prioritizeSendCandidates(
                    $plan['ranked_users'],
                    $lockedRecipients,
                );
                $revalidated = $this->revalidateDispatchUsers(
                    $incident,
                    $targetTeam,
                    $rankedCandidates,
                    ['dispatch_recipient_count' => $requestedCount],
                    (bool) $currentDispatch->includes_unavailable_recipients,
                );
                if ($revalidated['users']->isEmpty()) {
                    throw ValidationException::withMessages([
                        'dispatch' => [$revalidated['message']],
                    ]);
                }

                $selectedUsers = $revalidated['users'];
                $selectedUserIds = $selectedUsers
                    ->pluck('id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->all();
                $currentDispatch->recipients()->whereNotIn('user_id', $selectedUserIds)->delete();
                $existingRecipients = $lockedRecipients->keyBy(fn (DispatchRecipient $recipient): string => (string) $recipient->user_id);
                $alarmNotifiedAt = now();

                foreach ($selectedUsers as $user) {
                    $recipient = $existingRecipients->get((string) $user->id)
                        ?? new DispatchRecipient([
                            'dispatch_request_id' => $currentDispatch->id,
                            'user_id' => $user->id,
                        ]);
                    $wasAvailable = $wasPreannouncement && $recipient->response_status === 'accepted';
                    $recipient->fill([
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                        'response_status' => 'pending',
                        'response_note' => $wasAvailable
                            ? 'Was beschikbaar bij de vooraankondiging; wacht op reactie op de alarmering.'
                            : null,
                        'responded_at' => null,
                        // This field represents the current alarm notification;
                        // replace the earlier preannouncement timestamp.
                        'notified_at' => $alarmNotifiedAt,
                    ]);
                    $recipient->save();
                }

                $currentDispatch->recipients()
                    ->whereDoesntHave('user.fcmTokens', fn ($tokens) => $this->onlineOperatorTokenQuery($tokens))
                    ->delete();
                $currentDispatch->setRelation('incident', $incident);
                $currentDispatch->load([
                    'recipients.user.fcmTokens' => fn ($tokens) => $this->onlineOperatorTokenQuery($tokens),
                    'recipients.user.statuses' => fn ($statuses) => $statuses->latestPerUser(),
                ]);
                if ($currentDispatch->recipients->isEmpty()) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Er zijn geen online operator-devices meer beschikbaar voor deze alarmering.'],
                    ]);
                }

                $updates = ['status' => 'sent', 'sent_at' => $alarmNotifiedAt];
                if ($wasPreannouncement) {
                    $updates['message'] = $this->defaultDispatchMessage($incident);
                }
                $currentDispatch->update($updates);
                DispatchPushOutbox::query()
                    ->where('dispatch_request_id', $currentDispatch->id)
                    ->where('message_type', 'incident_preannouncement')
                    ->whereNull('delivered_at')
                    ->whereNull('cancelled_at')
                    ->update([
                        'cancelled_at' => now(),
                        'last_error_code' => 'superseded_by_alarm',
                        'updated_at' => now(),
                    ]);
                $dispatchTitle = $this->pushTemplate(
                    'dispatch_title',
                    'NDT Alarmering',
                    $this->pushTemplateTokens($incident),
                );
                $notificationCount = 0;

                foreach ($currentDispatch->recipients as $recipient) {
                    $user = $recipient->user;
                    $unavailableEscalation = $currentDispatch->includes_unavailable_recipients
                        && $user !== null
                        && ! $this->isOperationallyAvailable($user);
                    $notificationTitle = $unavailableEscalation
                        ? $this->pushTemplate(
                            'dispatch_unavailable_escalation_title',
                            'NDT urgente opschaling',
                            $this->pushTemplateTokens($incident),
                        )
                        : $dispatchTitle;
                    $notificationBody = $unavailableEscalation && $user !== null
                        ? $this->unavailableEscalationNotificationBody($currentDispatch, $user)
                        : $this->notificationBody($currentDispatch);
                    $notificationData = $this->notificationData($currentDispatch) + [
                        'unavailable_escalation' => $unavailableEscalation ? 'true' : 'false',
                    ];

                    foreach ($user?->fcmTokens ?? [] as $token) {
                        $this->dispatchPushOutboxService->store(
                            dispatchRequestId: (string) $currentDispatch->id,
                            fcmTokenId: (string) $token->id,
                            messageType: 'dispatch_request',
                            title: $notificationTitle,
                            body: $notificationBody,
                            data: $notificationData,
                        );
                        $notificationCount++;
                    }
                }
                if ($notificationCount === 0) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Er zijn geen online operator-devices meer beschikbaar voor deze alarmering.'],
                    ]);
                }

                $this->transitionIncidentStatus(
                    $incident,
                    $actor,
                    'dispatching',
                    'Automatisch naar alarmeren gezet nadat de alarmering is verstuurd.',
                );
                $this->auditService->record('dispatch.sent', $currentDispatch, $actor);
                $this->broadcastDispatchChange($currentDispatch, 'sent');

                return [
                    'dispatch' => $currentDispatch->refresh()->load(['incident', 'targetTeam', 'recipients']),
                ];
            });

            if ($result === null) {
                $dispatch->refresh();

                continue;
            }

            $this->flushDispatchPushOutboxAfterCommit((string) $result['dispatch']->id);

            return $result['dispatch'];
        }

        throw ValidationException::withMessages([
            'dispatch' => ['De incidentlocatie wijzigde tijdens de selectie. Probeer de alarmering opnieuw.'],
        ]);
    }

    private function flushDispatchPushOutboxAfterCommit(string $dispatchRequestId): void
    {
        $flush = fn (): bool => $this->flushDispatchPushOutboxNow($dispatchRequestId);
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($flush);

            return;
        }

        $flush();
    }

    public function flushPushOutboxForIncident(Incident $incident): void
    {
        try {
            $dispatchRequestIds = $incident->dispatchRequests()->pluck('id');
        } catch (Throwable $exception) {
            // The incident transition is already committed. Preserve the
            // successful API response; the scheduled outbox flush remains the
            // durable recovery path if this lookup is temporarily unavailable.
            Log::warning('Dispatch push outbox lookup failed after incident commit.', [
                'incident_id' => (string) $incident->id,
                'exception_class' => $exception::class,
            ]);

            return;
        }

        foreach ($dispatchRequestIds as $dispatchRequestId) {
            // One unavailable queue operation must not prevent another team
            // dispatch from being submitted during the same incident change.
            $this->flushDispatchPushOutboxNow((string) $dispatchRequestId);
        }
    }

    private function flushDispatchPushOutboxNow(string $dispatchRequestId): bool
    {
        try {
            $this->dispatchPushOutboxService->flushPending(500, $dispatchRequestId);

            return true;
        } catch (Throwable $exception) {
            // The alarm and outbox rows are already durable. A later
            // scheduler run will retry; never turn that committed alarm
            // into a misleading HTTP failure or log queue credentials.
            Log::warning('Dispatch push outbox flush failed after commit.', [
                'dispatch_request_id' => $dispatchRequestId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    public function respond(DispatchRequest $dispatch, User $actor, string $response, ?string $note): DispatchRecipient
    {
        $dispatch->loadMissing('incident');
        $isPreannouncement = $dispatch->status === 'draft';
        $isTestAlert = (bool) $dispatch->incident?->is_test;
        $recipient = $dispatch->recipients()->where('user_id', $actor->id)->firstOrFail();
        $recipient->update([
            'response_status' => $response,
            'response_note' => $note ?? $this->defaultResponseNote($response, $isPreannouncement),
            'responded_at' => now(),
        ]);
        $this->revokeLocationConsentAfterNonAttendance($dispatch, $actor, $actor, $response);
        $this->auditService->record('dispatch.responded', $dispatch, $actor, [
            'response' => $response,
            'action_mode' => $isTestAlert ? 'test_ack' : ($isPreannouncement ? 'availability' : 'attendance'),
        ]);
        $this->syncResponseToUserDevices($dispatch, $actor, $response);
        $this->broadcastDispatchChange($dispatch->refresh(), 'responded');
        if (! $isTestAlert && ! $isPreannouncement && $response === 'accepted') {
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
        $isTestAlert = (bool) $dispatch->incident?->is_test;
        $actionMode = $isTestAlert ? 'test_ack' : ($dispatch->status === 'draft' ? 'availability' : 'attendance');

        // The responder already applied this draft response locally and the
        // server remains the source of truth for their other devices. Do not
        // queue an availability synchronisation: normal-priority pushes can
        // arrive after the later high-priority alarm, and older clients then
        // dismiss that first real alarm for the same dispatch identifier.
        if ($actionMode === 'availability') {
            return;
        }

        $title = match ($actionMode) {
            'availability' => 'D.I.S beschikbaarheid bijgewerkt',
            'test_ack' => 'D.I.S proefalarmering bijgewerkt',
            default => 'D.I.S alarmering bijgewerkt',
        };
        $body = match ($actionMode) {
            'availability' => 'Je beschikbaarheid is verwerkt.',
            'test_ack' => 'Je ontvangstbevestiging is verwerkt.',
            default => 'Je reactie is verwerkt.',
        };

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
                    'is_test' => $isTestAlert ? 'true' : 'false',
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
        $recipient->loadMissing('user');
        if ($recipient->user !== null) {
            $this->revokeLocationConsentAfterNonAttendance($dispatch, $recipient->user, $actor, $response);
        }

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

    private function revokeLocationConsentAfterNonAttendance(
        DispatchRequest $dispatch,
        User $target,
        User $actor,
        string $response,
    ): void {
        if (! in_array($response, ['declined', 'no_response'], true)) {
            return;
        }

        $dispatch->loadMissing('incident');
        if ($dispatch->incident === null) {
            return;
        }

        $stillAttending = $dispatch->incident->dispatchRequests()
            ->whereIn('status', ['sent', 'escalated'])
            ->whereHas('recipients', fn ($recipients) => $recipients
                ->where('user_id', $target->id)
                ->where('response_status', 'accepted'))
            ->exists();
        if (! $stillAttending) {
            $this->locationService->revokeForIncident($dispatch->incident, $target, $actor);
        }
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
                        'action_mode' => 'additional_info',
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
     * @param  array<int, string>  $teamIds
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
     * @param  array<string, mixed>  $data
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
     * @param  array<string, mixed>  $data
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
     * @param  array<int, string>  $teamIds
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
     * @return array{ranked_users: Collection<int, User>, route_target: string, message: string}
     */
    private function prepareSendCandidatePlan(DispatchRequest $dispatch): array
    {
        $dispatch->load(['incident', 'targetTeam']);
        if ($dispatch->incident === null || $dispatch->targetTeam === null) {
            throw ValidationException::withMessages([
                'dispatch' => ['Deze alarmering mist een geldig incident of team.'],
            ]);
        }

        $selection = $this->selectDispatchUsers(
            $dispatch->incident,
            $dispatch->targetTeam,
            [],
            (bool) $dispatch->includes_unavailable_recipients,
        );

        return [
            'ranked_users' => $selection['ranked_users'],
            'route_target' => $this->incidentRouteFingerprint($dispatch->incident),
            'message' => $selection['message'],
        ];
    }

    /**
     * Keep pilots who accepted the preannouncement first, then unanswered
     * selected pilots, and finally ETA-ranked backfill candidates. Explicitly
     * declined/no-response recipients never receive the actual alarm.
     *
     * @param  Collection<int, User>  $rankedUsers
     * @param  Collection<int, DispatchRecipient>  $recipients
     * @return Collection<int, User>
     */
    private function prioritizeSendCandidates(Collection $rankedUsers, Collection $recipients): Collection
    {
        $statuses = $recipients->mapWithKeys(
            fn (DispatchRecipient $recipient): array => [(string) $recipient->user_id => $recipient->response_status],
        );

        return $rankedUsers
            ->values()
            ->map(function (User $user, int $index) use ($statuses): array {
                $status = $statuses->get((string) $user->id);

                return [
                    'user' => $user,
                    'index' => $index,
                    'priority' => match ($status) {
                        'accepted' => 0,
                        'pending' => 1,
                        null => 2,
                        default => 3,
                    },
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['priority'] < 3)
            ->sort(fn (array $left, array $right): int => ($left['priority'] <=> $right['priority'])
                ?: ($left['index'] <=> $right['index']))
            ->pluck('user')
            ->values();
    }

    private function incidentRouteFingerprint(Incident $incident): string
    {
        return $this->routePoint($incident->latitude, $incident->longitude)?->fingerprint() ?? 'no-route-target';
    }

    /**
     * @return array{users: Collection<int, User>, ranked_users: Collection<int, User>, message: string}
     */
    private function selectDispatchUsers(Incident $incident, Team $targetTeam, array $data, bool $includeUnavailable = false): array
    {
        $eligibility = $this->eligibleUsers($targetTeam, $includeUnavailable);
        $alreadyAcceptedUserIds = $this->acceptedAttendanceUserIds($incident);
        $candidates = $eligibility['users']
            ->reject(fn (User $user): bool => $alreadyAcceptedUserIds->contains($user->id))
            ->values();
        $routeEstimates = $this->routeEstimatesForUsers($incident, $candidates);
        $rankedUsers = $this->rankUsersByIncidentEta($candidates, $routeEstimates);
        $users = $rankedUsers;
        $requestedCount = $this->requestedRecipientCount($data);
        if ($requestedCount !== null) {
            $users = $users->take($requestedCount)->values();
        }
        $message = $eligibility['message'];
        if ($users->isEmpty() && $eligibility['users']->isNotEmpty()) {
            $message = "Alle alarmeerbare gebruikers voor team {$targetTeam->code} hebben voor dit incident al aangegeven dat ze komen.";
        }

        return [
            'users' => $users,
            'ranked_users' => $rankedUsers,
            'message' => $message,
        ];
    }

    /**
     * Recheck all volatile dispatch rules after routing I/O while preserving
     * the route order and keeping enough candidates to backfill a changed user.
     *
     * @param  Collection<int, User>  $rankedUsers
     * @return array{users: Collection<int, User>, message: string}
     */
    private function revalidateDispatchUsers(
        Incident $incident,
        Team $targetTeam,
        Collection $rankedUsers,
        array $data,
        bool $includeUnavailable,
    ): array {
        $eligibility = $this->eligibleUsers($targetTeam, $includeUnavailable);
        $eligibleUserIds = array_fill_keys(
            $eligibility['users']->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
            true,
        );
        $alreadyAcceptedUserIds = array_fill_keys(
            $this->acceptedAttendanceUserIds($incident)->map(fn (mixed $id): string => (string) $id)->all(),
            true,
        );
        $users = $rankedUsers
            ->filter(fn (User $user): bool => isset($eligibleUserIds[(string) $user->id])
                && ! isset($alreadyAcceptedUserIds[(string) $user->id]))
            ->values();

        $requestedCount = $this->requestedRecipientCount($data);
        if ($requestedCount !== null) {
            $users = $users->take($requestedCount)->values();
        }

        $message = $eligibility['message'];
        if ($users->isEmpty() && $eligibility['users']->isNotEmpty()) {
            $message = "Alle alarmeerbare gebruikers voor team {$targetTeam->code} hebben voor dit incident al aangegeven dat ze komen of hun geschiktheid is tijdens de selectie gewijzigd.";
        }

        return [
            'users' => $users,
            'message' => $message,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function acceptedAttendanceUserIds(Incident $incident): Collection
    {
        $incident->loadMissing('dispatchRequests.recipients');

        return $incident->dispatchRequests
            ->whereIn('status', ['sent', 'escalated'])
            ->flatMap(fn (DispatchRequest $dispatch): Collection => $dispatch->recipients)
            ->filter(fn (DispatchRecipient $recipient): bool => $recipient->response_status === 'accepted')
            ->pluck('user_id')
            ->unique()
            ->values();
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
                'fcmTokens' => fn ($tokens) => $this->onlineOperatorTokenQuery($tokens),
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
        $onlineTokenUsers = $pushEnabledUsers
            ->filter(fn (User $user): bool => $user->fcmTokens->isNotEmpty())
            ->values();
        $availableUsers = $includeUnavailable
            ? $onlineTokenUsers
            : $onlineTokenUsers
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
                'active_token_users' => $onlineTokenUsers->count(),
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
     * @param  Collection<int, User>  $users
     * @param  array<string, RouteEstimate>  $routeEstimates
     * @return Collection<int, User>
     */
    private function rankUsersByIncidentEta(Collection $users, array $routeEstimates): Collection
    {
        return $users
            ->map(function (User $user) use ($routeEstimates): array {
                $estimate = $routeEstimates[(string) $user->id] ?? RouteEstimate::unknown();

                return [
                    'user' => $user,
                    'source_priority' => match ($estimate->source) {
                        RouteSource::Navigation => 0,
                        RouteSource::Fallback => 1,
                        RouteSource::Unknown => 2,
                    },
                    'duration_seconds' => $estimate->duration,
                    'distance_meters' => $estimate->distance,
                ];
            })
            ->sort(function (array $left, array $right): int {
                return ($left['source_priority'] <=> $right['source_priority'])
                    ?: (($left['duration_seconds'] ?? PHP_INT_MAX) <=> ($right['duration_seconds'] ?? PHP_INT_MAX))
                    ?: (($left['distance_meters'] ?? PHP_INT_MAX) <=> ($right['distance_meters'] ?? PHP_INT_MAX))
                    ?: strcmp($left['user']->name, $right['user']->name);
            })
            ->pluck('user')
            ->values();
    }

    private function etaRingMinutes(RouteEstimate $estimate): ?int
    {
        if ($estimate->duration === null) {
            return null;
        }

        $ringMinutes = max(1, (int) config('dis.dispatch.eta_ring_minutes', 15));
        $minutes = $estimate->duration / 60;

        return max($ringMinutes, (int) ceil($minutes / $ringMinutes) * $ringMinutes);
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<string, RouteEstimate>
     */
    private function routeEstimatesForUsers(Incident $incident, Collection $users): array
    {
        $destination = $this->routePoint($incident->latitude, $incident->longitude);
        if ($destination === null) {
            return [];
        }

        $origins = [];
        foreach ($users as $user) {
            $origin = $this->routePoint($user->home_latitude, $user->home_longitude);
            if ($origin !== null) {
                $origins[(string) $user->id] = $origin;
            }
        }

        return $this->routingService->routesTo($origins, $destination);
    }

    private function routePoint(mixed $latitudeValue, mixed $longitudeValue): ?RoutePoint
    {
        $latitude = $this->coordinate($latitudeValue, -90, 90);
        $longitude = $this->coordinate($longitudeValue, -180, 180);

        return $latitude === null || $longitude === null
            ? null
            : new RoutePoint($latitude, $longitude);
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

    /**
     * @param  Collection<int, string>  $requiredCertificationIds
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
     * @param  array{team_users: int, active_users: int, push_enabled_users: int, active_token_users: int, available_users: int, certified_users: int, required_certifications: int}  $counts
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
            return "$prefix Teamleden hebben geen online operator-device.";
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
     * @return list<string>
     */
    private function validationMessages(ValidationException $exception): array
    {
        $messages = [];
        foreach ($exception->errors() as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                if (is_string($message) && trim($message) !== '') {
                    $messages[] = $message;
                }
            }
        }

        return array_values(array_unique($messages));
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

    private function onlineOperatorTokenQuery($tokens)
    {
        return $tokens
            ->where('is_active', true)
            ->where('client_type', 'operator')
            ->where('last_seen_at', '>', now()->subMinutes(FcmToken::pushReachabilityThresholdMinutes()));
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
        if ($incident === null || $incident->is_test || ! in_array($incident->status, ['active', 'dispatching'], true)) {
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

    public function placeNameFromLocation(?string $location): ?string
    {
        $value = trim((string) $location);
        if ($value === '') {
            return null;
        }

        $segments = array_values(array_filter(array_map(
            fn (string $segment): string => trim($segment),
            preg_split('/[,;|]/', $value) ?: [],
        )));

        foreach (array_reverse($segments) as $segment) {
            if (preg_match('/\b(?:B|BE)-?[1-9][0-9]{3}\s+/i', $segment) === 1) {
                $place = $this->placeFromBelgianPostalCodeSegment($segment);
                if ($place !== null) {
                    return $place;
                }
            }
        }

        $hasBelgianCountry = $this->hasBelgianCountrySegment($segments);
        if ($hasBelgianCountry) {
            foreach (array_reverse($segments) as $segment) {
                $place = $this->placeFromBelgianPostalCodeSegment($segment);
                if ($place !== null) {
                    return $place;
                }
            }

            foreach ($segments as $index => $segment) {
                if (preg_match('/^(?:B|BE)?-?[1-9][0-9]{3}$/i', trim($segment)) === 1) {
                    $place = $this->placeAfterDutchPostalCode($segments, $index + 1);
                    if ($place !== null) {
                        return $place;
                    }
                }
            }
        }

        if (! $hasBelgianCountry) {
            foreach ($segments as $index => $segment) {
                $dutchPostalCode = $this->placeFromDutchPostalCodeSegment($segment);
                if ($dutchPostalCode['matched']) {
                    if ($dutchPostalCode['place'] !== null) {
                        return $dutchPostalCode['place'];
                    }

                    return $this->placeAroundDutchPostalCode(
                        $segments,
                        $index,
                        $index + 1,
                        $this->isDutchPostalCodeOnlySegment($segment),
                    );
                }

                $splitPostalCodeEnd = $this->splitDutchPostalCodeEndIndex($segments, $index);
                if ($splitPostalCodeEnd !== null) {
                    return $this->placeAroundDutchPostalCode($segments, $index, $splitPostalCodeEnd + 1, true);
                }
            }
        }

        foreach (array_reverse($segments) as $segment) {
            $place = $this->placeFromBelgianPostalCodeSegment($segment);
            if ($place !== null) {
                return $place;
            }
        }

        $wholePlace = $this->placeFromBelgianPostalCodeSegment($value);
        if ($wholePlace !== null) {
            return $wholePlace;
        }

        foreach (array_reverse($segments !== [] ? $segments : [$value]) as $segment) {
            $place = $this->cleanPlaceCandidate($segment);
            if ($place !== null) {
                return $place;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $segments
     */
    private function hasBelgianCountrySegment(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (preg_match('/^(?:belgium|belgie|belgië|be)$/iu', trim($segment)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{matched: bool, place: string|null}
     */
    private function placeFromDutchPostalCodeSegment(string $segment): array
    {
        $segment = $this->cleanCountryNames($segment);

        if (preg_match('/\b[1-9][0-9]{3}\s*[A-Z]\s*[A-Z]\b(.*)$/i', $segment, $matches) !== 1) {
            return ['matched' => false, 'place' => null];
        }

        return [
            'matched' => true,
            'place' => $this->cleanPlaceCandidate((string) $matches[1], allowProvinceOnly: true),
        ];
    }

    /**
     * @param  list<string>  $segments
     */
    private function splitDutchPostalCodeEndIndex(array $segments, int $index): ?int
    {
        $current = trim($segments[$index] ?? '');
        $next = trim($segments[$index + 1] ?? '');
        $afterNext = trim($segments[$index + 2] ?? '');

        if (preg_match('/^[1-9][0-9]{3}$/', $current) === 1) {
            if (preg_match('/^[A-Z]\s*[A-Z]$/i', $next) === 1) {
                return $index + 1;
            }

            if (preg_match('/^[A-Z]$/i', $next) === 1 && preg_match('/^[A-Z]$/i', $afterNext) === 1) {
                return $index + 2;
            }
        }

        if (
            preg_match('/^[1-9][0-9]{3}\s*[A-Z]$/i', $current) === 1
            && preg_match('/^[A-Z]$/i', $next) === 1
        ) {
            return $index + 1;
        }

        return null;
    }

    /**
     * @param  list<string>  $segments
     */
    private function placeAfterDutchPostalCode(array $segments, int $startIndex): ?string
    {
        for ($index = $startIndex, $count = count($segments); $index < $count; $index++) {
            $place = $this->cleanPlaceCandidate($segments[$index], allowProvinceOnly: true);
            if ($place !== null) {
                return $place;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $segments
     */
    private function placeAroundDutchPostalCode(
        array $segments,
        int $postalCodeStartIndex,
        int $placeAfterStartIndex,
        bool $allowPlaceBefore,
    ): ?string {
        $placeAfter = $this->placeAfterDutchPostalCode($segments, $placeAfterStartIndex);
        $placeBefore = $allowPlaceBefore
            ? $this->placeBeforeDutchPostalCode($segments, $postalCodeStartIndex)
            : null;

        if ($placeBefore !== null && ($placeAfter === null || $this->isProvinceOnlyPlace($placeAfter))) {
            return $placeBefore;
        }

        return $placeAfter;
    }

    /**
     * @param  list<string>  $segments
     */
    private function placeBeforeDutchPostalCode(array $segments, int $postalCodeStartIndex): ?string
    {
        $candidate = trim($segments[$postalCodeStartIndex - 1] ?? '');
        if ($candidate === '' || preg_match('/\d/u', $candidate) === 1) {
            return null;
        }

        return $this->cleanPlaceCandidate($candidate, allowProvinceOnly: true);
    }

    private function isDutchPostalCodeOnlySegment(string $segment): bool
    {
        return preg_match('/^[1-9][0-9]{3}\s*[A-Z]\s*[A-Z]$/i', trim($segment)) === 1;
    }

    private function placeFromBelgianPostalCodeSegment(string $segment): ?string
    {
        $segment = $this->cleanCountryNames($segment);

        if (preg_match('/\b(?:B|BE)?-?[1-9][0-9]{3}\s+(.+)$/i', $segment, $matches) === 1) {
            return $this->cleanPlaceCandidate((string) $matches[1], allowProvinceOnly: true);
        }

        return null;
    }

    private function cleanPlaceCandidate(string $candidate, bool $allowProvinceOnly = false): ?string
    {
        $place = $this->cleanCountryNames($candidate);
        $place = trim((string) preg_replace('/\b[1-9][0-9]{3}\s*[A-Z]\s*[A-Z]\b/i', '', $place));
        $place = trim((string) preg_replace('/\b(?:B|BE)?-?[1-9][0-9]{3}\b/i', '', $place));
        $place = trim((string) preg_replace('/\s+/', ' ', $place));
        $place = trim($place, " \t\n\r\0\x0B,-");

        if (
            $place === ''
            || $this->isCountryOnlyPlace($place)
            || (! $allowProvinceOnly && $this->isProvinceOnlyPlace($place))
        ) {
            return null;
        }

        return $place;
    }

    private function cleanCountryNames(string $value): string
    {
        return trim((string) preg_replace(
            '/(?:^|[\s,;-]+)(?:the netherlands|netherlands|nederland|belgium|belgie|belgië|germany|duitsland|deutschland|nl|be|de)\s*$/iu',
            '',
            $value,
        ));
    }

    private function isCountryOnlyPlace(string $value): bool
    {
        return preg_match(
            '/^(?:netherlands|nederland|the netherlands|belgium|belgie|belgië|germany|duitsland|deutschland|nl|be|de)$/iu',
            trim($value),
        ) === 1;
    }

    private function isProvinceOnlyPlace(string $value): bool
    {
        return preg_match(
            '/^(?:north holland|noord-holland|south holland|zuid-holland|utrecht|gelderland|flevoland|friesland|fryslan|groningen|drenthe|overijssel|zeeland|north brabant|noord-brabant|limburg|antwerpen|vlaams-brabant|waals-brabant|west-vlaanderen|oost-vlaanderen|henegouwen|luik|luxemburg|namen|brussels|brussel)$/i',
            trim($value),
        ) === 1;
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
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function pushTemplateTokens(?Incident $incident, array $extra = []): array
    {
        $place = $extra['place'] ?? $this->placeNameFromLocation($incident?->location_label) ?? '';
        $address = (string) ($incident?->location_label ?? '');
        $latitude = (string) ($incident?->latitude ?? '');
        $longitude = (string) ($incident?->longitude ?? '');
        $coordinates = trim($latitude.($latitude !== '' && $longitude !== '' ? ', ' : '').$longitude);

        return array_merge([
            'reference' => (string) ($incident?->reference ?? ''),
            'title' => (string) ($incident?->title ?? ''),
            'description' => (string) ($incident?->description ?? ''),
            'location' => $address,
            'address' => $address,
            'place' => $place,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'coordinates' => $coordinates,
            'priority' => (string) ($incident?->priority ?? ''),
            'status' => (string) ($incident?->status ?? ''),
            'reporter_name' => $this->legacyFieldToken($incident, 'reporter_name'),
            'reporter_phone' => $this->legacyFieldToken($incident, 'reporter_phone'),
            'requesting_organization' => $this->legacyFieldToken($incident, 'requesting_organization'),
            'requesting_unit' => $this->legacyFieldToken($incident, 'requesting_unit'),
            'on_scene_contact_name' => $this->legacyFieldToken($incident, 'on_scene_contact_name'),
            'on_scene_contact_phone' => $this->legacyFieldToken($incident, 'on_scene_contact_phone'),
            'on_scene_contact_role' => $this->legacyFieldToken($incident, 'on_scene_contact_role'),
            'required_resources' => $this->legacyFieldToken($incident, 'required_resources'),
            'coordinator_name' => (string) ($incident?->coordinator_name ?? ''),
            'created_by_name' => (string) ($incident?->created_by_name ?? ''),
            'created_at' => (string) ($incident?->created_at?->format('d-m-Y H:i') ?? ''),
            'opened_at' => (string) ($incident?->opened_at?->format('d-m-Y H:i') ?? ''),
            'closed_at' => (string) ($incident?->closed_at?->format('d-m-Y H:i') ?? ''),
            'message' => '',
        ], $this->customFieldTokens($incident), $extra);
    }

    private function legacyFieldToken(?Incident $incident, string $key): string
    {
        if (! $this->fieldExposedToPush($key)) {
            return '';
        }

        return (string) ($incident?->{$key} ?? '');
    }

    private function fieldExposedToPush(string $key): bool
    {
        if ($this->incidentFormService->isFixedPushVariableKey($key)) {
            return true;
        }

        foreach ($this->incidentFormService->fields() as $field) {
            if (($field['key'] ?? null) === $key) {
                return ($field['expose_to_push'] ?? true) === true;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function customFieldTokens(?Incident $incident): array
    {
        if ($incident === null || ! is_array($incident->custom_fields)) {
            return [];
        }

        $tokens = [];
        foreach ($this->incidentFormService->fields() as $field) {
            if (($field['type'] ?? null) === 'section' || ($field['expose_to_push'] ?? true) !== true) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $value = $incident->custom_fields[$key] ?? null;
            $tokens['field_'.$key] = $this->stringifyCustomFieldValue($value);
        }

        return $tokens;
    }

    private function stringifyCustomFieldValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nee';
        }

        if (is_array($value)) {
            if (isset($value['start'], $value['end'])) {
                $duration = isset($value['duration_minutes']) && is_numeric($value['duration_minutes'])
                    ? ' ('.(int) $value['duration_minutes'].' min)'
                    : '';

                return trim((string) $value['start'].' - '.(string) $value['end'].$duration);
            }

            return implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, string>  $tokens
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
