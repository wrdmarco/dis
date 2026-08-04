<?php

namespace App\Services;

use App\DTO\Routing\RouteEstimate;
use App\DTO\Routing\RoutePoint;
use App\DTO\Routing\RouteSource;
use App\Events\DeploymentChanged;
use App\Events\DispatchChanged;
use App\Jobs\SendFcmNotification;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AvailabilityStatus;
use App\Models\Deployment;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\User;
use App\Repositories\DeploymentPilotAssignmentRepository;
use App\Services\Routing\RoutingService;
use App\Support\AssetReadiness;
use App\Support\FormFieldType;
use App\Support\FormFieldValue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DispatchService
{
    private const MAX_DEPLOYMENT_TEAMS = 50;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly AvailabilityScheduleService $availabilityScheduleService,
        private readonly DispatchPushOutboxService $dispatchPushOutboxService,
        private readonly NotificationTemplateTextNormalizer $notificationText,
        private readonly DeploymentFormService $deploymentFormService,
        private readonly DeploymentRequestWorkflowService $deploymentRequestWorkflowService,
        private readonly DeploymentRequestPlanSynchronizationService $deploymentRequestPlanSynchronizationService,
        private readonly DeploymentPilotAssignmentRepository $pilotAssignments,
        private readonly LocationService $locationService,
        private readonly RoutingService $routingService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Deployment $deployment, array $data, User $actor): DispatchRequest
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $deployment->refresh();
            if (in_array($deployment->status, ['resolved', 'cancelled'], true)) {
                throw ValidationException::withMessages(['deployment_id' => ['Voor een afgesloten inzet kan geen alarmering worden aangemaakt.']]);
            }

            $targetTeam = $this->targetTeam($deployment, $data);
            if ($targetTeam === null) {
                throw ValidationException::withMessages(['team_code' => ['Het gekozen team bestaat niet.']]);
            }

            // Route-provider I/O is intentionally completed before opening
            // this transaction. The target is fingerprinted so a concurrent
            // coordinate change cannot commit a stale ETA ranking.
            $routeTarget = $this->deploymentRouteFingerprint($deployment);
            $eligibility = $this->selectDispatchUsers($deployment, $targetTeam, $data, (bool) ($data['include_unavailable'] ?? false));
            if ($eligibility['users']->isEmpty()) {
                throw ValidationException::withMessages(['team_code' => [$eligibility['message']]]);
            }

            $created = DB::transaction(function () use ($deployment, $data, $actor, $targetTeam, $eligibility, $routeTarget): ?DispatchRequest {
                $deploymentRequest = $this->deploymentRequestPlanSynchronizationService
                    ->lockForDeployment((string) $deployment->id);
                $currentDeployment = Deployment::query()->lockForUpdate()->findOrFail($deployment->id);
                if (in_array($currentDeployment->status, ['resolved', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['deployment_id' => ['Voor een afgesloten inzet kan geen alarmering worden aangemaakt.']]);
                }
                $this->assertDeploymentRequestDecisionReady($currentDeployment);
                if ($this->deploymentRouteFingerprint($currentDeployment) !== $routeTarget) {
                    return null;
                }

                $currentTargetTeam = Team::query()
                    ->where('is_operational', true)
                    ->find($targetTeam->id);
                if ($currentTargetTeam === null) {
                    throw ValidationException::withMessages(['team_code' => ['Het gekozen team bestaat niet of is niet operationeel.']]);
                }
                if ($deploymentRequest !== null
                    && (string) $currentDeployment->team_id !== (string) $currentTargetTeam->id
                    && ! $currentDeployment->teams()->where('teams.id', $currentTargetTeam->id)->exists()) {
                    throw ValidationException::withMessages([
                        'target_team_id' => [
                            'Koppel een nieuw team eerst via de gesynchroniseerde opschalingsroute aan deze inzet.',
                        ],
                    ]);
                }

                // The deployment row is already locked. This serializes creators
                // even when no matching dispatch row exists yet, so two
                // concurrent activation requests cannot both pass this check.
                if (DispatchRequest::query()
                    ->where('deployment_id', $currentDeployment->id)
                    ->where('target_team_id', $currentTargetTeam->id)
                    ->where('status', '!=', 'cancelled')
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'target_team_id' => ['Voor deze inzet bestaat al een actieve alarmering voor het gekozen team.'],
                    ]);
                }

                $revalidated = $this->revalidateDispatchUsers(
                    $currentDeployment,
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
                    'deployment_id' => $currentDeployment->id,
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

                return $dispatch->load(['deployment', 'recipients']);
            });

            if ($created !== null) {
                return $created;
            }
        }

        throw ValidationException::withMessages([
            'deployment_id' => ['De inzetlocatie wijzigde tijdens de selectie. Probeer de alarmering opnieuw.'],
        ]);
    }

    /**
     * @return array{dispatch: DispatchRequest|null, warnings: list<string>}
     */
    public function createAndSendForDeploymentActivation(Deployment $deployment, User $actor, ?string $message = null, array $options = []): array
    {
        $existingDrafts = $deployment->dispatchRequests()
            ->where('status', 'draft')
            ->with(['deployment', 'recipients.user.fcmTokens' => fn ($tokens) => $this->reachableOperatorTokenQuery($tokens)])
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

        if ($deployment->dispatchRequests()->where('status', 'sent')->exists()) {
            return ['dispatch' => null, 'warnings' => []];
        }

        $dispatch = null;
        $warnings = [];
        $remaining = $this->requestedRecipientCount($options);
        foreach ($this->targetTeams($deployment, []) as $targetTeam) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            try {
                $created = $this->create($deployment, [
                    'priority' => $deployment->priority === 'low' ? 'normal' : $deployment->priority,
                    'message' => $message ?: $this->defaultDispatchMessage($deployment),
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
    public function sendPreannouncementForDeploymentActivation(Deployment $deployment, User $actor, ?string $message = null, array $options = []): array
    {
        $notification = $this->preannouncementNotification($deployment);
        $notificationTitle = $notification['title'];
        $notificationBody = $notification['body'];

        $queuedTokens = 0;
        $recipientCount = 0;
        $dispatches = collect();
        $warnings = [];
        $remaining = $this->requestedRecipientCount($options);
        foreach ($this->targetTeams($deployment, []) as $targetTeam) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $dispatch = $deployment->dispatchRequests()
                ->where('status', 'draft')
                ->where('target_team_id', $targetTeam->id)
                ->first();

            if ($dispatch === null) {
                try {
                    $dispatch = $this->create($deployment, [
                        'priority' => $deployment->priority === 'low' ? 'normal' : $deployment->priority,
                        'message' => $message ?: $this->defaultDispatchMessage($deployment),
                        'target_team_id' => $targetTeam->id,
                        'dispatch_recipient_count' => $remaining,
                    ] + $options, $actor);
                } catch (ValidationException $exception) {
                    $warnings = array_merge($warnings, $this->validationMessages($exception));

                    continue;
                }
            }

            try {
                $reconciled = $this->reconcileDraftPreannouncementRecipients(
                    $deployment,
                    $targetTeam,
                    $dispatch,
                    $remaining,
                );
            } catch (ValidationException $exception) {
                $warnings = array_merge($warnings, $this->validationMessages($exception));

                continue;
            }
            $dispatch = $reconciled['dispatch'];
            $recipients = $reconciled['recipients'];
            if ($reconciled['selection_changed']) {
                $warnings[] = "De ontvangerselectie voor team {$targetTeam->code} is bijgewerkt op basis van actuele geschiktheid en de ontvangerslimiet.";
            }
            if ($reconciled['selection_shortfall']) {
                $warnings[] = $reconciled['message'];
            }
            $dispatches->push($dispatch);
            $recipientCount += $recipients->count();
            if ($remaining !== null) {
                $remaining -= $recipients->count();
            }

            foreach ($recipients as $recipient) {
                foreach ($recipient->user?->fcmTokens ?? [] as $token) {
                    $this->dispatchPushOutboxService->store(
                        dispatchRequestId: (string) $dispatch->id,
                        fcmTokenId: (string) $token->id,
                        messageType: 'deployment_preannouncement',
                        title: $notificationTitle,
                        body: $notificationBody,
                        data: [
                            'type' => 'deployment_preannouncement',
                            'action_mode' => 'availability',
                            'deployment_id' => (string) $deployment->id,
                            'dispatch_id' => (string) $dispatch->id,
                        ],
                    );
                    $queuedTokens++;
                }
            }

            $preannouncedAt = now();
            // This timestamp identifies the start of the current wallboard
            // preannouncement window. Advance it for every explicit resend;
            // recipient notified_at remains the per-recipient delivery phase.
            $dispatch->forceFill(['preannounced_at' => $preannouncedAt])->save();
            $dispatch->recipients()
                ->whereKey($recipients->modelKeys())
                ->whereNull('notified_at')
                ->update(['notified_at' => $preannouncedAt]);
            $this->broadcastDispatchChange($dispatch->refresh(), 'preannouncement_sent');
            $this->flushDispatchPushOutboxAfterCommit((string) $dispatch->id);
        }

        if ($recipientCount === 0) {
            throw ValidationException::withMessages([
                'dispatch' => $warnings !== [] ? $warnings : ['Er zijn geen alarmeerbare gebruikers beschikbaar voor deze vooraankondiging.'],
            ]);
        }

        $this->auditService->record('deployments.preannouncement_sent', $deployment, $actor, [
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
    public function sendCancellationForActiveDeployment(Deployment $deployment, User $actor): array
    {
        $deployment->load([
            'dispatchRequests.recipients.user.fcmTokens' => fn ($tokens) => $this->reachableOperatorTokenQuery($tokens),
        ]);

        $recipients = $deployment->dispatchRequests
            ->where('status', 'draft')
            ->flatMap(fn (DispatchRequest $dispatch): Collection => $dispatch->recipients)
            ->unique('user_id')
            ->values();

        if ($recipients->isEmpty()) {
            foreach ($this->targetTeams($deployment, []) as $targetTeam) {
                $recipients = $recipients->merge(
                    $this->eligibleUsers($targetTeam, false, false)['users']
                        ->map(fn (User $user): object => (object) ['user' => $user, 'user_id' => $user->id]),
                );
            }
            $recipients = $recipients->unique('user_id')->values();
        }

        $notification = $this->cancellationNotification($deployment);
        $title = $notification['title'];
        $body = $notification['body'];

        $queuedTokens = 0;
        foreach ($recipients as $recipient) {
            foreach ($recipient->user?->fcmTokens ?? [] as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'deployment_cancelled',
                    $title,
                    $body,
                    [
                        'type' => 'deployment_cancelled',
                        'deployment_id' => (string) $deployment->id,
                    ],
                )->onQueue('push');
                $queuedTokens++;
            }
        }

        $this->auditService->record('deployments.active_cancelled_notification_sent', $deployment, $actor, [
            'recipient_users' => $recipients->count(),
            'queued_tokens' => $queuedTokens,
        ]);

        return [
            'queued_tokens' => $queuedTokens,
            'recipient_users' => $recipients->count(),
        ];
    }

    public function invalidateDraftsAfterDeploymentRequestChange(Deployment $deployment, User $actor): void
    {
        DB::transaction(function () use ($deployment, $actor): void {
            $currentDeployment = Deployment::query()->lockForUpdate()->findOrFail($deployment->id);
            $drafts = $currentDeployment->dispatchRequests()
                ->where('status', 'draft')
                ->lockForUpdate()
                ->get();

            foreach ($drafts as $dispatch) {
                $dispatch->load([
                    'recipients' => fn ($recipients) => $recipients->whereNotNull('notified_at'),
                    'recipients.user.fcmTokens' => fn ($tokens) => $this->reachableOperatorTokenQuery($tokens),
                ]);
                $notifiedTokenIds = $dispatch->recipients
                    ->flatMap(fn (DispatchRecipient $recipient): Collection => $recipient->user?->fcmTokens ?? collect())
                    ->pluck('id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->unique()
                    ->values()
                    ->all();
                $dispatch->forceFill([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ])->save();
                DispatchPushOutbox::query()
                    ->where('dispatch_request_id', $dispatch->id)
                    ->whereNull('delivered_at')
                    ->whereNull('cancelled_at')
                    ->update([
                        'cancelled_at' => now(),
                        'last_error_code' => 'deployment_request_decision_invalidated',
                        'updated_at' => now(),
                    ]);
                $this->auditService->record('dispatch.cancelled_after_deployment_request_change', $dispatch, $actor, [
                    'deployment_id' => $currentDeployment->id,
                    'notified_recipient_devices' => count($notifiedTokenIds),
                ]);
                $this->broadcastDispatchChange($dispatch->refresh(), 'cancelled_after_deployment_request_change');
                foreach ($notifiedTokenIds as $fcmTokenId) {
                    DB::afterCommit(fn () => SendFcmNotification::dispatch(
                        $fcmTokenId,
                        'deployment_preannouncement_cancelled',
                        'D.I.S vooraankondiging bijgewerkt',
                        'De eerdere vooraankondiging is ingetrokken. Wacht op een nieuwe alarmering.',
                        [
                            'type' => 'deployment_preannouncement_cancelled',
                            'action_mode' => 'availability_cancelled',
                            'deployment_id' => (string) $currentDeployment->id,
                            'dispatch_id' => (string) $dispatch->id,
                        ],
                        (string) $dispatch->id,
                    )->onQueue('push'));
                }
            }
        });
    }

    /**
     * @return array{team: array<string, mixed>|null, recipients: list<array<string, mixed>>, blocked_reason: string|null, warnings: list<string>}
     */
    public function previewForDeployment(Deployment $deployment, array $options = []): array
    {
        $targetTeams = $this->targetTeams($deployment, []);
        if ($targetTeams->isEmpty()) {
            return [
                'team' => null,
                'teams' => [],
                'recipients' => [],
                'blocked_reason' => 'Er is geen geldig team voor deze mogelijke inzet gekozen.',
                'warnings' => [],
            ];
        }

        $eligibleUsers = collect();
        $blockedReasons = [];
        $manualAssignmentUserIds = $this->manualAssignmentUserIdMap($deployment);
        foreach ($targetTeams as $targetTeam) {
            $eligibility = $this->eligibleDispatchUsers(
                $deployment,
                $targetTeam,
                (bool) ($options['include_unavailable'] ?? false),
                manualAssignmentUserIds: $manualAssignmentUserIds,
            );
            $eligibleUsers = $eligibleUsers->merge($eligibility['users']);
            if ($eligibility['users']->isEmpty()) {
                $blockedReasons[] = $eligibility['message'];
            }
        }
        $eligibleUsers = $eligibleUsers->unique('id')->values();
        $routeEstimates = $this->routeEstimatesForUsers($deployment, $eligibleUsers);
        $eligibleUsers = $this->rankUsersByDeploymentEta($eligibleUsers, $routeEstimates);
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

            return $dispatch->load(['deployment', 'targetTeam', 'recipients']);
        }
        if ($dispatch->status !== 'draft') {
            throw ValidationException::withMessages([
                'dispatch' => ['Alleen een conceptalarmering kan worden verstuurd.'],
            ]);
        }

        // Routing stays outside this transaction. The destination fingerprint
        // is checked again under the deployment lock; one concurrent location
        // change causes a fresh selection instead of using a stale ranking.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $plan = $this->prepareSendCandidatePlan($dispatch);
            $dispatchMetadata = DispatchRequest::query()
                ->select(['id', 'deployment_id'])
                ->find($dispatch->id);
            if ($dispatchMetadata === null) {
                throw ValidationException::withMessages([
                    'dispatch' => ['Deze alarmering bestaat niet meer.'],
                ]);
            }
            $dispatchDeploymentId = (string) $dispatchMetadata->deployment_id;

            $result = DB::transaction(function () use ($dispatch, $actor, $plan, $dispatchDeploymentId): ?array {
                // Every operational write uses the same parent-to-child lock
                // order: deployment, dispatch, recipient/outbox. This avoids the
                // former deadlock with deployment activation holding the deployment
                // row while a direct send held the dispatch row.
                $deployment = Deployment::query()->lockForUpdate()->find($dispatchDeploymentId);
                if ($deployment === null) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['De inzet van deze alarmering bestaat niet meer.'],
                    ]);
                }
                $currentDispatch = DispatchRequest::query()->lockForUpdate()->find($dispatch->id);
                if ($currentDispatch === null) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Deze alarmering bestaat niet meer.'],
                    ]);
                }
                if ((string) $currentDispatch->deployment_id !== $dispatchDeploymentId) {
                    return null;
                }
                if ($currentDispatch->status === 'sent') {
                    return [
                        'dispatch' => $currentDispatch->load(['deployment', 'targetTeam', 'recipients']),
                    ];
                }
                if ($currentDispatch->status !== 'draft') {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Alleen een conceptalarmering kan worden verstuurd.'],
                    ]);
                }

                if (in_array($deployment->status, ['resolved', 'cancelled'], true)) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Voor een afgesloten inzet kan geen alarmering worden verstuurd.'],
                    ]);
                }
                $this->assertDeploymentRequestDecisionReady($deployment);
                if ($this->deploymentRouteFingerprint($deployment) !== $plan['route_target']) {
                    return null;
                }

                $targetTeam = Team::query()
                    ->where('is_operational', true)
                    ->find($currentDispatch->target_team_id);
                if ($targetTeam === null) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Het team van deze alarmering bestaat niet meer of is niet operationeel.'],
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
                    $deployment,
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
                    ->whereDoesntHave('user.fcmTokens', fn ($tokens) => $this->reachableOperatorTokenQuery($tokens))
                    ->delete();
                $currentDispatch->setRelation('deployment', $deployment);
                $currentDispatch->load([
                    'recipients.user.fcmTokens' => fn ($tokens) => $this->reachableOperatorTokenQuery($tokens),
                    'recipients.user.statuses' => fn ($statuses) => $statuses->latestPerUser(),
                ]);
                if ($currentDispatch->recipients->isEmpty()) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Er zijn geen bereikbare operator-devices meer beschikbaar voor deze alarmering.'],
                    ]);
                }

                $updates = [
                    'status' => 'sent',
                    'sent_at' => $alarmNotifiedAt,
                    'send_status' => 'queued_for_push',
                    'send_queued_at' => $alarmNotifiedAt,
                    'send_released_at' => $alarmNotifiedAt,
                ];
                if ($wasPreannouncement) {
                    $updates['message'] = $this->defaultDispatchMessage($deployment);
                }
                $currentDispatch->update($updates);
                DispatchPushOutbox::query()
                    ->where('dispatch_request_id', $currentDispatch->id)
                    ->where('message_type', 'deployment_preannouncement')
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
                    $this->pushTemplateTokens($deployment),
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
                            $this->pushTemplateTokens($deployment),
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
                        'dispatch' => ['Er zijn geen bereikbare operator-devices meer beschikbaar voor deze alarmering.'],
                    ]);
                }

                $this->transitionDeploymentStatus(
                    $deployment,
                    $actor,
                    'dispatching',
                    'Automatisch naar alarmeren gezet nadat de alarmering is verstuurd.',
                );
                $this->auditService->record('dispatch.sent', $currentDispatch, $actor);
                $this->broadcastDispatchChange($currentDispatch, 'sent');

                return [
                    'dispatch' => $currentDispatch->refresh()->load(['deployment', 'targetTeam', 'recipients']),
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
            'dispatch' => ['De inzetlocatie wijzigde tijdens de selectie. Probeer de alarmering opnieuw.'],
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

    public function flushPushOutboxForDeployment(Deployment $deployment): void
    {
        $flush = function () use ($deployment): void {
            $this->flushPushOutboxForCommittedDeployment($deployment);
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($flush);

            return;
        }

        $flush();
    }

    private function flushPushOutboxForCommittedDeployment(Deployment $deployment): void
    {
        try {
            $dispatchRequestIds = $deployment->dispatchRequests()->pluck('id');
        } catch (Throwable $exception) {
            // The deployment transition is already committed. Preserve the
            // successful API response; the scheduled outbox flush remains the
            // durable recovery path if this lookup is temporarily unavailable.
            Log::warning('Dispatch push outbox lookup failed after deployment commit.', [
                'deployment_id' => (string) $deployment->id,
                'exception_class' => $exception::class,
            ]);

            return;
        }

        foreach ($dispatchRequestIds as $dispatchRequestId) {
            // One unavailable queue operation must not prevent another team
            // dispatch from being submitted during the same deployment change.
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
        [$dispatch, $recipient] = DB::transaction(function () use ($dispatch, $actor, $response, $note): array {
            $deployment = Deployment::query()
                ->whereKey($dispatch->deployment_id)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch = DispatchRequest::query()
                ->whereKey($dispatch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch->setRelation('deployment', $deployment);
            $recipient = $dispatch->recipients()
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertDispatchResponseDoesNotConflictWithManualAssignment(
                $deployment,
                $dispatch,
                (string) $actor->id,
                $response,
            );
            $recipient->update([
                'response_status' => $response,
                'response_note' => $note ?? $this->defaultResponseNote($response, $dispatch->status === 'draft'),
                'responded_at' => now(),
            ]);

            return [$dispatch, $recipient];
        });

        $isPreannouncement = $dispatch->status === 'draft';
        $isTestAlert = (bool) $dispatch->deployment?->is_test;
        $this->revokeLocationConsentAfterNonAttendance($dispatch, $actor, $actor, $response);
        $this->auditService->record('dispatch.responded', $dispatch, $actor, [
            'recipient_id' => $recipient->id,
            'user_id' => $actor->id,
            'response' => $response,
            'action_mode' => $isTestAlert ? 'test_ack' : ($isPreannouncement ? 'availability' : 'attendance'),
        ]);
        $this->syncResponseToUserDevices($dispatch, $actor, $response);
        $this->broadcastDispatchChange($dispatch->refresh(), 'responded');
        if (! $isTestAlert && ! $isPreannouncement && $response === 'accepted') {
            $this->transitionDeploymentToInProgressWhenEveryoneOnScene($dispatch->refresh(), $actor);
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
        $isTestAlert = (bool) $dispatch->deployment?->is_test;
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
                    'deployment_id' => (string) $dispatch->deployment_id,
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

        [$dispatch, $recipient] = DB::transaction(function () use ($dispatch, $recipient, $response, $note): array {
            $deployment = Deployment::query()
                ->whereKey($dispatch->deployment_id)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch = DispatchRequest::query()
                ->whereKey($dispatch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch->setRelation('deployment', $deployment);
            $recipient = DispatchRecipient::query()
                ->whereKey($recipient->id)
                ->where('dispatch_request_id', $dispatch->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($recipient->user_id !== null) {
                $this->assertDispatchResponseDoesNotConflictWithManualAssignment(
                    $deployment,
                    $dispatch,
                    (string) $recipient->user_id,
                    $response,
                );
            }
            $recipient->update([
                'response_status' => $response,
                'response_note' => $note,
                'responded_at' => $response === 'pending' ? null : now(),
            ]);

            return [$dispatch, $recipient];
        });

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
            $this->transitionDeploymentToInProgressWhenEveryoneOnScene($dispatch->refresh(), $actor);
        }

        return $recipient->refresh()->load('user');
    }

    private function assertDispatchResponseDoesNotConflictWithManualAssignment(
        Deployment $deployment,
        DispatchRequest $dispatch,
        string $userId,
        string $response,
    ): void {
        if (! in_array($dispatch->status, ['sent', 'escalated'], true)
            || ! in_array($response, ['pending', 'accepted'], true)
            || ! $this->pilotAssignments->hasManualAssignmentForUser($deployment, $userId)) {
            return;
        }

        throw ValidationException::withMessages([
            'response' => [
                'Deze piloot is al handmatig aan de inzet gekoppeld. De eerdere alarmreactie kan niet opnieuw op “Wacht op reactie” of “Ik kom” worden gezet.',
            ],
        ]);
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

        $dispatch->loadMissing('deployment');
        if ($dispatch->deployment === null) {
            return;
        }

        $stillAttending = $dispatch->deployment->dispatchRequests()
            ->whereIn('status', ['sent', 'escalated'])
            ->whereHas('recipients', fn ($recipients) => $recipients
                ->where('user_id', $target->id)
                ->where('response_status', 'accepted'))
            ->exists();
        $stillAttending = $stillAttending || $dispatch->deployment->pilotAssignments()
            ->where('user_id', $target->id)
            ->exists();
        if (! $stillAttending) {
            $this->locationService->revokeForDeployment($dispatch->deployment, $target, $actor);
        }
    }

    /**
     * @return array{queued_tokens: int, recipient_users: int}
     */
    public function sendAdditionalInfo(DispatchRequest $dispatch, User $actor, string $message): array
    {
        $dispatch->load([
            'deployment.pilotAssignments.user.fcmTokens',
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
        $dispatchParticipants = $dispatch->recipients
            ->filter(fn (DispatchRecipient $recipient): bool => $recipient->response_status === 'accepted'
                || in_array($recipient->user?->statuses->first()?->status, ['en_route', 'on_scene'], true))
            ->pluck('user')
            ->filter()
            ->values();
        $manualParticipants = $dispatch->deployment === null
            ? collect()
            : $dispatch->deployment->pilotAssignments
                ->pluck('user')
                ->filter()
                ->values();
        $participants = $dispatchParticipants
            ->merge($manualParticipants)
            ->unique('id')
            ->values();

        $queuedTokens = 0;
        $tokens = $this->pushTemplateTokens($dispatch->deployment, ['message' => $message]);
        $title = $this->pushTemplate('additional_info_title', 'D.I.S aanvullende info', $tokens);
        $body = $this->pushTemplate('additional_info_body', '{{message}}', $tokens);
        foreach ($participants as $participant) {
            foreach ($participant->fcmTokens->where('is_active', true) as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'dispatch_update',
                    $title,
                    $body,
                    [
                        'type' => 'dispatch_update',
                        'action_mode' => 'additional_info',
                        'dispatch_id' => (string) $dispatch->id,
                        'deployment_id' => (string) $dispatch->deployment_id,
                    ],
                    (string) $dispatch->id,
                )->onQueue('push');
                $queuedTokens++;
            }
        }

        $this->auditService->record('dispatch.additional_info_sent', $dispatch, $actor, [
            'message_id' => $dispatchMessage->id,
            'recipient_users' => $participants->count(),
            'queued_tokens' => $queuedTokens,
        ]);
        $this->broadcastDispatchChange($dispatch->refresh(), 'additional_info_sent');

        return [
            'queued_tokens' => $queuedTokens,
            'recipient_users' => $participants->count(),
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

        $dispatch->loadMissing(['deployment.dispatchRequests', 'deployment.teams']);
        if ($includeUnavailable && ! in_array($dispatch->deployment?->priority, ['high', 'critical'], true)) {
            throw ValidationException::withMessages([
                'include_unavailable' => ['Niet-beschikbare teamleden mogen alleen bij urgente inzetten worden gealarmeerd.'],
            ]);
        }

        $newTeams = $this->teamsForEscalation($dispatch, $teamIds);
        $deployment = $dispatch->deployment;
        if ($deployment === null) {
            throw ValidationException::withMessages(['dispatch' => ['Deze alarmering is niet gekoppeld aan een inzet.']]);
        }
        $manualAssignmentUserIds = $this->manualAssignmentUserIdMap($deployment);
        $eligibility = $newTeams->mapWithKeys(fn (Team $team): array => [
            $team->id => $this->eligibleDispatchUsers(
                $deployment,
                $team,
                $includeUnavailable,
                manualAssignmentUserIds: $manualAssignmentUserIds,
            ),
        ]);
        $blocked = $eligibility->filter(fn (array $result): bool => $result['users']->isEmpty());

        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'team_ids' => $blocked->map(fn (array $result): string => $result['message'])->values()->all(),
            ]);
        }

        return DB::transaction(function () use ($dispatch, $actor, $teamIds, $includeUnavailable): DispatchRequest {
            // Linked request mutations always lock the request before the
            // deployment. Keep that order here so an operational team
            // expansion cannot deadlock with an intake update or redecision.
            $deploymentRequest = $this->deploymentRequestPlanSynchronizationService
                ->lockForDeployment((string) $dispatch->deployment_id);
            $deployment = Deployment::query()
                ->with(['dispatchRequests', 'teams'])
                ->lockForUpdate()
                ->findOrFail($dispatch->deployment_id);
            $currentDispatch = DispatchRequest::query()
                ->lockForUpdate()
                ->find($dispatch->id);
            if ($currentDispatch === null
                || (string) $currentDispatch->deployment_id !== (string) $deployment->id) {
                throw ValidationException::withMessages([
                    'dispatch' => ['Deze alarmering bestaat niet meer of hoort niet bij deze inzet.'],
                ]);
            }
            if ($currentDispatch->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'dispatch' => ['Een geannuleerde alarmering kan niet worden opgeschaald.'],
                ]);
            }
            if ($includeUnavailable && ! in_array($deployment->priority, ['high', 'critical'], true)) {
                throw ValidationException::withMessages([
                    'include_unavailable' => ['Niet-beschikbare teamleden mogen alleen bij urgente inzetten worden gealarmeerd.'],
                ]);
            }

            $deployment->load(['dispatchRequests', 'teams']);
            $currentDispatch->setRelation('deployment', $deployment);
            $currentNewTeams = $this->teamsForEscalation($currentDispatch, $teamIds);
            $resultingTeamIds = $deployment->teams
                ->pluck('id')
                ->merge($currentNewTeams->pluck('id'))
                ->unique()
                ->values();
            if ($resultingTeamIds->count() > self::MAX_DEPLOYMENT_TEAMS) {
                throw ValidationException::withMessages([
                    'team_ids' => ['Een inzet kan aan maximaal 50 operationele teams worden gekoppeld.'],
                ]);
            }

            if ($currentNewTeams->isNotEmpty()) {
                $deployment->teams()->syncWithoutDetaching($currentNewTeams->pluck('id')->all());
                $deployment->forceFill(['team_id' => $deployment->team_id ?? $currentNewTeams->first()?->id])->save();

                foreach ($currentNewTeams as $team) {
                    $created = $this->create($deployment->refresh(), [
                        'priority' => $currentDispatch->priority,
                        'message' => $currentDispatch->message ?: $this->defaultDispatchMessage($deployment),
                        'target_team_id' => $team->id,
                        'include_unavailable' => $includeUnavailable,
                    ], $actor);

                    $this->markSent($created, $actor);
                }

                if ($deploymentRequest !== null) {
                    $this->deploymentRequestPlanSynchronizationService->synchronizeTeams(
                        $deploymentRequest,
                        $deployment->refresh(),
                        $actor,
                    );
                }
            }

            $currentDispatch->update(['status' => 'escalated', 'cancelled_at' => null]);
            $this->auditService->record('dispatch.escalated', $currentDispatch, $actor, [
                'added_team_ids' => $currentNewTeams->pluck('id')->values()->all(),
                'added_team_codes' => $currentNewTeams->pluck('code')->values()->all(),
                'include_unavailable' => $includeUnavailable,
            ]);
            $this->broadcastDispatchChange($currentDispatch->refresh(), 'escalated');

            return $currentDispatch->load(['deployment', 'targetTeam', 'recipients.user']);
        });
    }

    public function cancel(DispatchRequest $dispatch, User $actor): DispatchRequest
    {
        $metadata = DispatchRequest::query()
            ->select(['id', 'deployment_id'])
            ->find($dispatch->id);
        if ($metadata === null) {
            throw ValidationException::withMessages([
                'dispatch' => ['Deze alarmering bestaat niet meer.'],
            ]);
        }

        return DB::transaction(function () use ($metadata, $actor): DispatchRequest {
            $deployment = Deployment::query()
                ->lockForUpdate()
                ->find($metadata->deployment_id);
            if ($deployment === null) {
                throw ValidationException::withMessages([
                    'dispatch' => ['De inzet van deze alarmering bestaat niet meer.'],
                ]);
            }
            $currentDispatch = DispatchRequest::query()
                ->lockForUpdate()
                ->find($metadata->id);
            if ($currentDispatch === null
                || (string) $currentDispatch->deployment_id !== (string) $deployment->id) {
                throw ValidationException::withMessages([
                    'dispatch' => ['Deze alarmering bestaat niet meer of hoort niet bij deze inzet.'],
                ]);
            }
            if ($currentDispatch->status !== 'cancelled') {
                $currentDispatch->forceFill([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ])->save();
                $this->auditService->record('dispatch.cancelled', $currentDispatch, $actor, [
                    'deployment_id' => $deployment->id,
                ]);
                $this->broadcastDispatchChange($currentDispatch->refresh(), 'cancelled');
            }

            return $currentDispatch->load(['deployment', 'targetTeam', 'recipients.user']);
        });
    }

    public function reAlert(DispatchRequest $dispatch, User $actor): DispatchRequest
    {
        $metadata = DispatchRequest::query()
            ->select(['id', 'deployment_id'])
            ->find($dispatch->id);
        if ($metadata === null) {
            throw ValidationException::withMessages(['dispatch' => ['Deze alarmering bestaat niet meer.']]);
        }
        $deploymentId = (string) $metadata->deployment_id;

        return DB::transaction(function () use ($dispatch, $actor, $deploymentId): DispatchRequest {
            // Use the same parent-to-child order as create/markSent so an
            // A deployment-request edit cannot race a new definitive re-alarm.
            $deployment = Deployment::query()->lockForUpdate()->find($deploymentId);
            if ($deployment === null) {
                throw ValidationException::withMessages(['dispatch' => ['De inzet van deze alarmering bestaat niet meer.']]);
            }
            $currentDispatch = DispatchRequest::query()->lockForUpdate()->find($dispatch->id);
            if ($currentDispatch === null || (string) $currentDispatch->deployment_id !== $deploymentId) {
                throw ValidationException::withMessages(['dispatch' => ['Deze alarmering bestaat niet meer.']]);
            }
            if ($currentDispatch->status === 'cancelled') {
                throw ValidationException::withMessages(['dispatch' => ['Een geannuleerde alarmering kan niet opnieuw worden verstuurd.']]);
            }
            if (in_array($deployment->status, ['resolved', 'cancelled'], true)) {
                throw ValidationException::withMessages(['dispatch' => ['Voor een afgesloten inzet kan geen heralarmering worden verstuurd.']]);
            }
            $this->assertDeploymentRequestDecisionReady($deployment);
            $targetTeam = null;
            $eligibility = null;
            if ($currentDispatch->target_team_id !== null) {
                $targetTeam = Team::query()
                    ->whereKey($currentDispatch->target_team_id)
                    ->where('is_operational', true)
                    ->first();
                if ($targetTeam === null) {
                    throw ValidationException::withMessages(['dispatch' => ['Het team van deze alarmering is niet operationeel.']]);
                }
                $eligibility = $this->eligibleUsers(
                    $targetTeam,
                    (bool) $currentDispatch->includes_unavailable_recipients,
                );
            }

            $pendingRecipients = $currentDispatch->recipients()
                ->where('response_status', 'pending')
                ->lockForUpdate()
                ->get();
            if ($pendingRecipients->isNotEmpty()) {
                $pendingRecipients->load([
                    'user.fcmTokens' => fn ($tokens) => $this->reachableOperatorTokenQuery($tokens),
                ]);
                $eligibleUserIds = $eligibility !== null
                    ? array_fill_keys(
                        $eligibility['users']->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
                        true,
                    )
                    : ($deployment->is_test
                        ? array_fill_keys(
                            $pendingRecipients->pluck('user_id')->map(fn (mixed $id): string => (string) $id)->all(),
                            true,
                        )
                        : $this->effectivelyAssetReadyUserIdMap($pendingRecipients->pluck('user_id')));
                $pendingRecipients = $pendingRecipients
                    ->filter(fn (DispatchRecipient $recipient): bool => isset($eligibleUserIds[(string) $recipient->user_id])
                        && $recipient->user?->fcmTokens->isNotEmpty())
                    ->values();
                if ($pendingRecipients->isEmpty()) {
                    $message = $eligibility['message'] ?? ($deployment->is_test
                        ? 'Geen openstaande proefalarmontvanger heeft nog een bereikbaar operator-device.'
                        : 'Geen openstaande ontvanger heeft nog een bereikbaar operator-device en een actief toegewezen inzetgereed middel.');
                    throw ValidationException::withMessages(['dispatch' => [$message]]);
                }
            }
            $currentDispatch->setRelation('deployment', $deployment);
            $queuedTokens = 0;

            foreach ($pendingRecipients as $recipient) {
                foreach ($recipient->user?->fcmTokens ?? [] as $token) {
                    SendFcmNotification::dispatch(
                        (string) $token->id,
                        'dispatch_request',
                        'NDT Heralarmering',
                        $this->notificationBody($currentDispatch, 'Reactie vereist'),
                        $this->notificationData($currentDispatch),
                        (string) $currentDispatch->id,
                    )->onQueue('push');
                    $queuedTokens++;
                }
            }

            $currentDispatch->recipients()
                ->whereKey($pendingRecipients->modelKeys())
                ->update(['notified_at' => now()]);
            $this->auditService->record('dispatch.realerted', $currentDispatch, $actor, ['queued_tokens' => $queuedTokens]);
            $this->broadcastDispatchChange($currentDispatch->refresh(), 'realerted');

            return $currentDispatch->load(['deployment', 'targetTeam', 'recipients.user']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function targetTeam(Deployment $deployment, array $data): ?Team
    {
        if (isset($data['target_team_id'])) {
            return Team::query()
                ->where('is_operational', true)
                ->find((string) $data['target_team_id']);
        }

        if (isset($data['team_code'])) {
            return Team::query()
                ->where('is_operational', true)
                ->where('code', (string) $data['team_code'])
                ->first();
        }

        if ($deployment->team_id !== null) {
            return Team::query()
                ->where('is_operational', true)
                ->find($deployment->team_id);
        }

        return Team::query()
            ->where('is_operational', true)
            ->where('code', (string) config('dis.teams.base_team_code', 'OCP'))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, Team>
     */
    private function targetTeams(Deployment $deployment, array $data): Collection
    {
        if (isset($data['target_team_id']) || isset($data['team_code'])) {
            $targetTeam = $this->targetTeam($deployment, $data);

            return $targetTeam === null ? collect() : collect([$targetTeam]);
        }

        $deployment->loadMissing('teams');
        if ($deployment->teams->isNotEmpty()) {
            return Team::query()
                ->whereIn('id', $deployment->teams->pluck('id')->all())
                ->where('is_operational', true)
                ->get()
                ->values();
        }

        $targetTeam = $this->targetTeam($deployment, []);

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

        $deployment = $dispatch->deployment;
        if ($deployment === null) {
            throw ValidationException::withMessages(['dispatch' => ['Deze alarmering is niet gekoppeld aan een inzet.']]);
        }

        $alreadyDispatchedTeamIds = $deployment->dispatchRequests
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
            throw ValidationException::withMessages(['team_ids' => ['Kies minimaal een operationeel team dat nog niet voor deze inzet is gealarmeerd.']]);
        }

        return $teams;
    }

    /**
     * @return array{ranked_users: Collection<int, User>, route_target: string, message: string}
     */
    private function prepareSendCandidatePlan(DispatchRequest $dispatch): array
    {
        $dispatch->load(['deployment', 'targetTeam']);
        if ($dispatch->deployment === null || $dispatch->targetTeam === null) {
            throw ValidationException::withMessages([
                'dispatch' => ['Deze alarmering mist een geldige inzet of een geldig team.'],
            ]);
        }

        $selection = $this->selectDispatchUsers(
            $dispatch->deployment,
            $dispatch->targetTeam,
            [],
            (bool) $dispatch->includes_unavailable_recipients,
        );

        return [
            'ranked_users' => $selection['ranked_users'],
            'route_target' => $this->deploymentRouteFingerprint($dispatch->deployment),
            'message' => $selection['message'],
        ];
    }

    /**
     * Replace stale draft recipients with current eligible candidates while
     * preserving accepted/pending preannouncement responses where possible.
     *
     * @return array{
     *     dispatch: DispatchRequest,
     *     recipients: Collection<int, DispatchRecipient>,
     *     selection_changed: bool,
     *     selection_shortfall: bool,
     *     message: string
     * }
     */
    private function reconcileDraftPreannouncementRecipients(
        Deployment $deployment,
        Team $targetTeam,
        DispatchRequest $dispatch,
        ?int $maximumRecipientCount,
    ): array {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $plan = $this->prepareSendCandidatePlan($dispatch);
            $result = DB::transaction(function () use (
                $deployment,
                $targetTeam,
                $dispatch,
                $maximumRecipientCount,
                $plan,
            ): ?array {
                $currentDeployment = Deployment::query()->lockForUpdate()->find($deployment->id);
                if ($currentDeployment === null || in_array($currentDeployment->status, ['resolved', 'cancelled'], true)) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Voor een afgesloten of ontbrekende inzet kan geen vooraankondiging worden verstuurd.'],
                    ]);
                }
                $this->assertDeploymentRequestDecisionReady($currentDeployment);
                if ($this->deploymentRouteFingerprint($currentDeployment) !== $plan['route_target']) {
                    return null;
                }

                $currentDispatch = DispatchRequest::query()->lockForUpdate()->find($dispatch->id);
                if ($currentDispatch === null
                    || (string) $currentDispatch->deployment_id !== (string) $currentDeployment->id
                    || (string) $currentDispatch->target_team_id !== (string) $targetTeam->id) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Deze conceptalarmering bestaat niet meer of hoort niet bij het gekozen team.'],
                    ]);
                }
                if ($currentDispatch->status !== 'draft') {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Alleen een conceptalarmering kan als vooraankondiging worden verstuurd.'],
                    ]);
                }

                $currentTargetTeam = Team::query()
                    ->whereKey($currentDispatch->target_team_id)
                    ->where('is_operational', true)
                    ->first();
                if ($currentTargetTeam === null) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Het team van deze conceptalarmering is niet operationeel.'],
                    ]);
                }

                $lockedRecipients = $currentDispatch->recipients()->lockForUpdate()->get();
                $requestedCount = $lockedRecipients->count();
                if ($maximumRecipientCount !== null) {
                    $requestedCount = min($requestedCount, $maximumRecipientCount);
                }
                if ($requestedCount <= 0) {
                    throw ValidationException::withMessages([
                        'dispatch' => ['Deze conceptalarmering heeft geen ontvangers.'],
                    ]);
                }

                $rankedCandidates = $this->prioritizeSendCandidates(
                    $plan['ranked_users'],
                    $lockedRecipients,
                );
                $revalidated = $this->revalidateDispatchUsers(
                    $currentDeployment,
                    $currentTargetTeam,
                    $rankedCandidates,
                    ['dispatch_recipient_count' => $requestedCount],
                    (bool) $currentDispatch->includes_unavailable_recipients,
                );
                $selectedUsers = $revalidated['users'];
                if ($selectedUsers->isEmpty()) {
                    throw ValidationException::withMessages([
                        'dispatch' => [$revalidated['message']],
                    ]);
                }

                $selectedUserIds = $selectedUsers
                    ->pluck('id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->all();
                $originalUserIds = $lockedRecipients
                    ->pluck('user_id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->sort()
                    ->values()
                    ->all();
                $sortedSelectedUserIds = collect($selectedUserIds)->sort()->values()->all();
                $selectionChanged = $originalUserIds !== $sortedSelectedUserIds;
                $selectionShortfall = $selectedUsers->count() < $requestedCount;

                $currentDispatch->recipients()->whereNotIn('user_id', $selectedUserIds)->delete();
                $existingRecipients = $lockedRecipients->keyBy(
                    fn (DispatchRecipient $recipient): string => (string) $recipient->user_id,
                );
                foreach ($selectedUsers as $user) {
                    $recipient = $existingRecipients->get((string) $user->id);
                    if ($recipient === null) {
                        DispatchRecipient::query()->create([
                            'dispatch_request_id' => $currentDispatch->id,
                            'user_id' => $user->id,
                            'user_name' => $user->name,
                            'user_email' => $user->email,
                            'response_status' => 'pending',
                        ]);

                        continue;
                    }

                    $recipient->forceFill([
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                    ])->save();
                }

                $currentDispatch->load([
                    'deployment',
                    'targetTeam',
                    'recipients.user.fcmTokens' => fn ($tokens) => $this->reachableOperatorTokenQuery($tokens),
                ]);

                return [
                    'dispatch' => $currentDispatch,
                    'recipients' => $currentDispatch->recipients->values(),
                    'selection_changed' => $selectionChanged,
                    'selection_shortfall' => $selectionShortfall,
                    'message' => "Voor team {$currentTargetTeam->code} zijn {$selectedUsers->count()} van de {$requestedCount} gevraagde ontvangers alarmeerbaar.",
                ];
            });

            if ($result !== null) {
                return $result;
            }
        }

        throw ValidationException::withMessages([
            'dispatch' => ['De inzetlocatie wijzigde tijdens de ontvangerselectie. Probeer de vooraankondiging opnieuw.'],
        ]);
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

    private function deploymentRouteFingerprint(Deployment $deployment): string
    {
        return $this->routePoint($deployment->latitude, $deployment->longitude)?->fingerprint() ?? 'no-route-target';
    }

    /**
     * @return array{users: Collection<int, User>, ranked_users: Collection<int, User>, message: string}
     */
    private function selectDispatchUsers(Deployment $deployment, Team $targetTeam, array $data, bool $includeUnavailable = false): array
    {
        $eligibility = $this->eligibleUsers($targetTeam, $includeUnavailable);
        $alreadyAcceptedUserIds = $this->acceptedAttendanceUserIds($deployment);
        $candidates = $eligibility['users']
            ->reject(fn (User $user): bool => $alreadyAcceptedUserIds->contains($user->id))
            ->values();
        $routeEstimates = $this->routeEstimatesForUsers($deployment, $candidates);
        $rankedUsers = $this->rankUsersByDeploymentEta($candidates, $routeEstimates);
        $users = $rankedUsers;
        $requestedCount = $this->requestedRecipientCount($data);
        if ($requestedCount !== null) {
            $users = $users->take($requestedCount)->values();
        }
        $message = $eligibility['message'];
        if ($users->isEmpty() && $eligibility['users']->isNotEmpty()) {
            $message = "Alle alarmeerbare gebruikers voor team {$targetTeam->code} hebben voor deze inzet al aangegeven dat ze komen.";
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
        Deployment $deployment,
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
            $this->acceptedAttendanceUserIds($deployment)->map(fn (mixed $id): string => (string) $id)->all(),
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
            $message = "Alle alarmeerbare gebruikers voor team {$targetTeam->code} hebben voor deze inzet al aangegeven dat ze komen of hun geschiktheid is tijdens de selectie gewijzigd.";
        }

        return [
            'users' => $users,
            'message' => $message,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function acceptedAttendanceUserIds(Deployment $deployment): Collection
    {
        $deployment->loadMissing('dispatchRequests.recipients');

        $dispatchParticipantUserIds = $deployment->dispatchRequests
            ->whereIn('status', ['sent', 'escalated'])
            ->flatMap(fn (DispatchRequest $dispatch): Collection => $dispatch->recipients)
            ->filter(fn (DispatchRecipient $recipient): bool => $recipient->response_status === 'accepted')
            ->pluck('user_id')
            ->values();
        $manualParticipantUserIds = $deployment->pilotAssignments()
            ->whereNotNull('user_id')
            ->pluck('user_id');

        return $dispatchParticipantUserIds
            ->merge($manualParticipantUserIds)
            ->map(fn (mixed $userId): string => (string) $userId)
            ->unique()
            ->values();
    }

    /**
     * @param  array<string, true>|null  $manualAssignmentUserIds
     * @return array{users: Collection<int, User>, message: string}
     */
    private function eligibleDispatchUsers(
        Deployment $deployment,
        Team $targetTeam,
        bool $includeUnavailable = false,
        ?array $manualAssignmentUserIds = null,
    ): array {
        $eligibility = $this->eligibleUsers($targetTeam, $includeUnavailable);
        if ($eligibility['users']->isEmpty()) {
            return $eligibility;
        }

        $manualAssignmentUserIds ??= $this->manualAssignmentUserIdMap($deployment);
        if ($manualAssignmentUserIds === []) {
            return $eligibility;
        }

        $eligibleUsers = $eligibility['users']
            ->reject(fn (User $user): bool => isset($manualAssignmentUserIds[(string) $user->id]))
            ->values();
        if ($eligibleUsers->isEmpty()) {
            $eligibility['message'] = "Alle alarmeerbare gebruikers voor team {$targetTeam->code} zijn al handmatig aan deze inzet gekoppeld.";
        }
        $eligibility['users'] = $eligibleUsers;

        return $eligibility;
    }

    /**
     * @return array<string, true>
     */
    private function manualAssignmentUserIdMap(Deployment $deployment): array
    {
        $userIds = $deployment->pilotAssignments()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn (mixed $userId): string => (string) $userId)
            ->filter(fn (string $userId): bool => $userId !== '')
            ->unique()
            ->all();

        return array_fill_keys($userIds, true);
    }

    /**
     * @return array{users: Collection<int, User>, message: string}
     */
    private function eligibleUsers(
        Team $targetTeam,
        bool $includeUnavailable = false,
        bool $requireReadyAsset = true,
    ): array {
        if (! $targetTeam->is_operational) {
            return [
                'users' => collect(),
                'message' => "Team {$targetTeam->code} is niet operationeel en kan niet worden gealarmeerd.",
            ];
        }

        $targetTeam->loadMissing('requiredCertifications');
        $requiredCertificationIds = $targetTeam->requiredCertifications->pluck('id');
        $teamCodes = $this->expandTeamCodes($targetTeam);

        $teamUsers = User::query()
            ->with([
                'certifications',
                'assetAssignments' => fn ($assignments) => $assignments
                    ->whereNull('released_at')
                    ->whereHas('asset', fn ($assets) => $this->constrainUniquelyAssignedEffectivelyReadyAsset($assets))
                    ->with('asset'),
                'fcmTokens' => fn ($tokens) => $this->reachableOperatorTokenQuery($tokens),
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
        $assetReadyUsers = $requireReadyAsset
            ? $certifiedUsers
                ->filter(fn (User $user): bool => $user->assetAssignments->contains(
                    fn (AssetAssignment $assignment): bool => $assignment->asset !== null
                        && AssetReadiness::isEffectivelyReady($assignment->asset),
                ))
                ->values()
            : $certifiedUsers;

        return [
            'users' => $assetReadyUsers,
            'message' => $this->eligibilityFailureMessage($targetTeam, [
                'team_users' => $teamUsers->count(),
                'active_users' => $activeUsers->count(),
                'push_enabled_users' => $pushEnabledUsers->count(),
                'active_token_users' => $onlineTokenUsers->count(),
                'available_users' => $availableUsers->count(),
                'certified_users' => $certifiedUsers->count(),
                'asset_ready_users' => $assetReadyUsers->count(),
                'required_certifications' => $requiredCertificationIds->count(),
            ], $requireReadyAsset),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $userIds
     * @return array<string, true>
     */
    private function effectivelyAssetReadyUserIdMap(Collection $userIds): array
    {
        $ids = $userIds
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $readyUserIds = User::query()
            ->whereIn('id', $ids->all())
            ->whereHas('assetAssignments', fn ($assignments) => $assignments
                ->whereNull('released_at')
                ->whereHas('asset', fn ($assets) => $this->constrainUniquelyAssignedEffectivelyReadyAsset($assets)))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_fill_keys($readyUserIds, true);
    }

    /**
     * Existing duplicate open assignments are treated as unsafe data: one
     * physical asset may never make multiple recipients dispatch-eligible.
     *
     * @param  Builder<Asset>  $assets
     * @return Builder<Asset>
     */
    private function constrainUniquelyAssignedEffectivelyReadyAsset(Builder $assets): Builder
    {
        return AssetReadiness::constrainEffectivelyReady($assets)
            ->whereHas(
                'assignments',
                fn (Builder $assignments) => $assignments->whereNull('released_at'),
                '=',
                1,
            );
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
    private function rankUsersByDeploymentEta(Collection $users, array $routeEstimates): Collection
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
    private function routeEstimatesForUsers(Deployment $deployment, Collection $users): array
    {
        $destination = $this->routePoint($deployment->latitude, $deployment->longitude);
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
     * @param  array{team_users: int, active_users: int, push_enabled_users: int, active_token_users: int, available_users: int, certified_users: int, asset_ready_users: int, required_certifications: int}  $counts
     */
    private function eligibilityFailureMessage(Team $team, array $counts, bool $requireReadyAsset): string
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
            return "$prefix Teamleden hebben geen bereikbaar operator-device.";
        }

        if ($counts['available_users'] === 0) {
            return "$prefix Teamleden zijn niet beschikbaar volgens hun laatste status.";
        }

        if ($counts['required_certifications'] > 0 && $counts['certified_users'] === 0) {
            return "$prefix Beschikbare teamleden missen een verplichte geldige certificering.";
        }

        if ($requireReadyAsset && $counts['asset_ready_users'] === 0) {
            return "$prefix Beschikbare gecertificeerde teamleden hebben geen actief toegewezen inzetgereed middel.";
        }

        return $requireReadyAsset
            ? "$prefix Controleer team, push-token, beschikbaarheid, certificeringen en middelen."
            : "$prefix Controleer team, push-token, beschikbaarheid en certificeringen.";
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

    private function assertDeploymentRequestDecisionReady(Deployment $deployment): void
    {
        if ($deployment->deployment_request_decision_valid) {
            return;
        }

        throw ValidationException::withMessages([
            'dispatch' => ['Beoordeel de bijgewerkte uitvraag opnieuw voordat je een alarmering maakt of verstuurt.'],
        ]);
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

    private function reachableOperatorTokenQuery($tokens)
    {
        return $tokens->reachable();
    }

    public function broadcastDispatchChange(DispatchRequest $dispatch, string $action): void
    {
        try {
            DispatchChanged::dispatch($dispatch, $action);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function transitionDeploymentStatus(Deployment $deployment, User $actor, string $status, string $reason): void
    {
        DB::transaction(function () use ($deployment, $actor, $status, $reason): void {
            $deployment = Deployment::query()
                ->lockForUpdate()
                ->find($deployment->getKey());
            if ($deployment === null || in_array($deployment->status, ['resolved', 'cancelled', $status], true)) {
                return;
            }

            $transitionAllowed = match ($status) {
                'dispatching' => in_array($deployment->status, ['draft', 'active'], true),
                'in_progress' => $deployment->status === 'dispatching',
                default => false,
            };
            if (! $transitionAllowed) {
                return;
            }

            $previousStatus = $deployment->status;
            $deployment->forceFill(['status' => $status])->save();
            $deployment->statusHistory()->create([
                'from_status' => $previousStatus,
                'to_status' => $status,
                'changed_by' => $actor->id,
                'changed_by_name' => $actor->name,
                'changed_by_email' => $actor->email,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $this->auditService->record('deployments.status_auto_updated', $deployment, $actor, [
                'from_status' => $previousStatus,
                'to_status' => $status,
            ], $reason);
            $this->broadcastDeploymentChange($deployment->refresh(), 'status_auto_updated');
        });
    }

    private function transitionDeploymentToInProgressWhenEveryoneOnScene(DispatchRequest $dispatch, User $actor): void
    {
        $dispatch->loadMissing(['deployment.dispatchRequests.recipients', 'deployment.pilotAssignments']);
        $deployment = $dispatch->deployment;
        if ($deployment === null || $deployment->is_test || $deployment->status !== 'dispatching') {
            return;
        }

        $participantUserIds = $deployment->dispatchRequests
            ->whereIn('status', ['sent', 'escalated'])
            ->flatMap(fn (DispatchRequest $existingDispatch) => $existingDispatch->recipients)
            ->filter(fn (DispatchRecipient $recipient): bool => $recipient->response_status === 'accepted')
            ->pluck('user_id')
            ->merge($deployment->pilotAssignments->pluck('user_id'))
            ->filter(fn (mixed $userId): bool => is_string($userId) && $userId !== '')
            ->unique()
            ->values();

        if ($participantUserIds->isEmpty()) {
            return;
        }

        $latestStatuses = AvailabilityStatus::query()
            ->latestPerUser()
            ->whereIn('user_id', $participantUserIds->all())
            ->pluck('status', 'user_id');

        $everyoneOnScene = $participantUserIds
            ->every(fn (string $userId): bool => $latestStatuses->get($userId) === 'on_scene');

        if ($everyoneOnScene) {
            $this->transitionDeploymentStatus(
                $deployment,
                $actor,
                'in_progress',
                'Automatisch naar uitvoering gezet omdat alle geaccepteerde opkomers op locatie zijn.',
            );
        }
    }

    private function broadcastDeploymentChange(Deployment $deployment, string $action): void
    {
        try {
            DeploymentChanged::dispatch($deployment, $action);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function defaultDispatchMessage(Deployment $deployment): string
    {
        $tokens = $this->pushTemplateTokens($deployment);
        $parts = [
            $tokens['reference'] ?? '',
            $tokens['title'] ?? '',
            $tokens['location'] ?? '',
        ];

        return implode(' - ', array_values(array_filter($parts, fn (?string $part): bool => filled($part))));
    }

    /** @return array{title: string, body: string} */
    private function preannouncementNotification(Deployment $deployment): array
    {
        $visibleTokens = $this->pushTemplateTokens($deployment);
        $place = trim($visibleTokens['place'] ?? '');
        // A preannouncement is intentionally minimal. It may disclose the
        // permitted place, but no title, address, postcode or other deployment
        // details before the definitive alarm.
        $tokens = $this->pushTemplateTokens(null, ['place' => $place]);

        return [
            'title' => $this->pushTemplate('preannouncement_title', 'D.I.S vooraankondiging', $tokens),
            'body' => $this->pushTemplate(
                'preannouncement_body',
                $place === '' ? 'Ben je beschikbaar voor een mogelijke inzet?' : 'Ben je beschikbaar voor een mogelijke inzet in {{place}}?',
                $tokens,
            ),
        ];
    }

    /** @return array{title: string, body: string} */
    private function cancellationNotification(Deployment $deployment): array
    {
        $visibleTokens = $this->pushTemplateTokens($deployment);
        $place = trim($visibleTokens['place'] ?? '');
        $tokens = $this->pushTemplateTokens(null, ['place' => $place]);

        return [
            'title' => $this->pushTemplate('cancellation_title', 'D.I.S geannuleerd', $tokens),
            'body' => $this->pushTemplate(
                'cancellation_body',
                $place === '' ? 'De vooraankondiging is geannuleerd.' : 'De vooraankondiging in {{place}} is geannuleerd.',
                $tokens,
            ),
        ];
    }

    private function rawDefaultDispatchMessage(Deployment $deployment): string
    {
        return implode(' - ', array_values(array_filter([
            $deployment->reference,
            $deployment->title,
            $deployment->location_label,
        ], fn (?string $part): bool => filled($part))));
    }

    private function dispatchMessageForOperator(DispatchRequest $dispatch): string
    {
        $message = trim((string) $dispatch->message);
        $deployment = $dispatch->deployment;
        if ($deployment === null) {
            return $message;
        }
        if ($message === '' || hash_equals($this->rawDefaultDispatchMessage($deployment), $message)) {
            return $this->defaultDispatchMessage($deployment);
        }

        return $message;
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
        $deployment = $dispatch->deployment;
        $tokens = $this->pushTemplateTokens($deployment);

        return [
            'type' => 'dispatch_request',
            'action_mode' => 'attendance',
            'is_test' => $deployment?->is_test ? 'true' : 'false',
            'dispatch_id' => (string) $dispatch->id,
            'deployment_id' => (string) $dispatch->deployment_id,
            'deployment_reference' => (string) ($deployment?->reference ?? ''),
            'deployment_title' => $tokens['title'] ?? '',
            'deployment_location' => $tokens['location'] ?? '',
            'dispatch_message' => $this->dispatchMessageForOperator($dispatch),
            'priority' => (string) $dispatch->priority,
        ];
    }

    private function notificationBody(DispatchRequest $dispatch, ?string $prefix = null): string
    {
        $message = $this->dispatchMessageForOperator($dispatch);

        if ($dispatch->deployment !== null) {
            $message = $this->pushTemplate('dispatch_body', $message, $this->pushTemplateTokens($dispatch->deployment, [
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
        $message = $this->dispatchMessageForOperator($dispatch);

        $tokens = $this->pushTemplateTokens($dispatch->deployment, [
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
    private function pushTemplateTokens(?Deployment $deployment, array $extra = []): array
    {
        $hiddenTargets = $deployment === null
            ? []
            : $this->deploymentRequestWorkflowService->hiddenDeploymentTargetsForOperator($deployment);
        $locationHidden = in_array('location_label', $hiddenTargets, true);
        $place = $extra['place'] ?? $this->placeNameFromLocation($deployment?->location_label) ?? '';
        $address = (string) ($deployment?->location_label ?? '');
        $postcode = preg_match('/\b[1-9][0-9]{3}\s*[A-Z]{2}\b/iu', $address, $postcodeMatch) === 1
            ? $this->notificationText->displayPostcode($postcodeMatch[0])
            : '';
        $province = $this->notificationText->plain((string) ($deployment?->province_name ?? ''));
        $latitude = (string) ($deployment?->latitude ?? '');
        $longitude = (string) ($deployment?->longitude ?? '');
        $coordinates = trim($latitude.($latitude !== '' && $longitude !== '' ? ', ' : '').$longitude);

        $tokens = array_merge([
            'reference' => (string) ($deployment?->reference ?? ''),
            'title' => (string) ($deployment?->title ?? ''),
            'description' => (string) ($deployment?->description ?? ''),
            'location' => $address,
            'address' => $address,
            'place' => $place,
            'postcode' => $postcode,
            'province' => $province,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'coordinates' => $coordinates,
            'priority' => (string) ($deployment?->priority ?? ''),
            'status' => (string) ($deployment?->status ?? ''),
            'reporter_name' => $this->legacyFieldToken($deployment, 'reporter_name', $hiddenTargets),
            'reporter_phone' => $this->legacyFieldToken($deployment, 'reporter_phone', $hiddenTargets),
            'requesting_organization' => $this->legacyFieldToken($deployment, 'requesting_organization', $hiddenTargets),
            'requesting_unit' => $this->legacyFieldToken($deployment, 'requesting_unit', $hiddenTargets),
            'on_scene_contact_name' => $this->legacyFieldToken($deployment, 'on_scene_contact_name', $hiddenTargets),
            'on_scene_contact_phone' => $this->legacyFieldToken($deployment, 'on_scene_contact_phone', $hiddenTargets),
            'on_scene_contact_role' => $this->legacyFieldToken($deployment, 'on_scene_contact_role', $hiddenTargets),
            'required_resources' => $this->legacyFieldToken($deployment, 'required_resources', $hiddenTargets),
            'coordinator_name' => (string) ($deployment?->coordinator_name ?? ''),
            'created_by_name' => (string) ($deployment?->created_by_name ?? ''),
            'created_at' => (string) ($deployment?->created_at?->format('d-m-Y H:i') ?? ''),
            'opened_at' => (string) ($deployment?->opened_at?->format('d-m-Y H:i') ?? ''),
            'closed_at' => (string) ($deployment?->closed_at?->format('d-m-Y H:i') ?? ''),
            'message' => '',
        ], $this->customFieldTokens($deployment, $hiddenTargets), $extra);

        foreach ($hiddenTargets as $target) {
            $tokens[$target] = '';
            if (str_starts_with($target, 'custom_fields.')) {
                $tokens['field_'.substr($target, strlen('custom_fields.'))] = '';
            } elseif (in_array($target, DeploymentRequestWorkflowService::LEGACY_MIRRORED_FIELD_KEYS, true)) {
                $tokens['field_'.$target] = '';
            }
        }
        if ($locationHidden) {
            foreach (['location', 'address', 'place', 'postcode', 'province', 'latitude', 'longitude', 'coordinates'] as $key) {
                $tokens[$key] = '';
            }
        }

        return $tokens;
    }

    /** @param list<string> $hiddenTargets */
    private function legacyFieldToken(?Deployment $deployment, string $key, array $hiddenTargets): string
    {
        if (in_array($key, $hiddenTargets, true) || ! $this->fieldExposedToPush($key)) {
            return '';
        }

        return (string) ($deployment?->{$key} ?? '');
    }

    private function fieldExposedToPush(string $key): bool
    {
        if ($this->deploymentFormService->isFixedPushVariableKey($key)) {
            return true;
        }

        foreach ($this->deploymentFormService->fields() as $field) {
            if (($field['key'] ?? null) === $key) {
                return ($field['expose_to_push'] ?? true) === true;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    /** @param list<string> $hiddenTargets */
    private function customFieldTokens(?Deployment $deployment, array $hiddenTargets): array
    {
        if ($deployment === null || ! is_array($deployment->custom_fields)) {
            return [];
        }

        $tokens = [];
        foreach ($this->deploymentFormService->fields() as $field) {
            if (($field['type'] ?? null) === 'section' || ($field['expose_to_push'] ?? true) !== true) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $value = $deployment->custom_fields[$key] ?? null;
            $target = DeploymentRequestWorkflowService::canonicalBindingTarget('custom_fields.'.$key);
            $tokens['field_'.$key] = in_array($target, $hiddenTargets, true)
                ? ''
                : $this->stringifyCustomFieldValue($value, $field);
        }

        return $tokens;
    }

    /** @param array<string, mixed>|null $field */
    private function stringifyCustomFieldValue(mixed $value, ?array $field = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (($field['type'] ?? null) === 'score' && is_numeric($value)) {
            return FormFieldType::scoreDisplay((int) $value) ?? (string) $value;
        }

        if (in_array($field['type'] ?? null, ['select', 'radio'], true)) {
            $option = collect($field['options'] ?? [])->firstWhere('value', $value);
            if (is_array($option)) {
                return (string) ($option['label'] ?? $value);
            }
        }

        if (($field['type'] ?? null) === 'date') {
            try {
                $date = FormFieldValue::normalizeDate($value, 'value');

                return CarbonImmutable::parse($date, 'UTC')->format('d-m-Y');
            } catch (Throwable) {
                return trim((string) $value);
            }
        }

        if (($field['type'] ?? null) === 'datetime') {
            try {
                $dateTime = FormFieldValue::normalizeDateTime($value, 'value');

                return CarbonImmutable::parse($dateTime)
                    ->setTimezone('Europe/Amsterdam')
                    ->format('d-m-Y H:i');
            } catch (Throwable) {
                return trim((string) $value);
            }
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
