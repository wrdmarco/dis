<?php

namespace App\Http\Controllers;

use App\Http\Requests\Deployments\StoreDeploymentRequest;
use App\Http\Requests\Deployments\UpdateDeploymentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\AvailabilityStatus;
use App\Models\Deployment;
use App\Models\DispatchRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repositories\DeploymentRepository;
use App\Services\DeploymentAccessService;
use App\Services\DeploymentRequestService;
use App\Services\DeploymentService;
use App\Services\DispatchService;
use App\Services\DroneFlightContextService;
use App\Support\ApiDateTime;
use App\Support\DeploymentTimelineAttribution;
use App\Support\DeploymentTimelineResponsePresentation;
use App\Support\DeploymentTimelineVisibility;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class DeploymentController extends Controller
{
    private const DEFAULT_APP_VISIBLE_TIMELINE_TYPES = ['status', 'dispatch', 'dispatch_response', 'dispatch_message', 'operator_status'];

    private const APP_VISIBLE_TIMELINE_TYPES = ['status', 'dispatch', 'dispatch_response', 'dispatch_message', 'operator_status', 'audit'];

    public function __construct(
        private readonly DeploymentRepository $deployments,
        private readonly DeploymentService $service,
        private readonly DispatchService $dispatchService,
        private readonly DroneFlightContextService $droneFlightContextService,
        private readonly DeploymentAccessService $access,
        private readonly DeploymentRequestService $deploymentRequestService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanListDeployments($request->user());

        if ($request->user()->isOperatorClient() || $request->boolean('active_alarms')) {
            $userId = $request->user()->id;
            $attendanceDispatchStatuses = ['sent', 'escalated'];
            $deployments = Deployment::query()
                ->with([
                    'coordinator',
                    'team',
                    'teams',
                    'deploymentRequest.workflowRevision',
                    'dispatchRequests' => fn ($dispatches) => $dispatches
                        ->where(function ($query) use ($userId, $attendanceDispatchStatuses): void {
                            $query
                                ->where(function ($preannouncement) use ($userId): void {
                                    $preannouncement
                                        ->where('status', 'draft')
                                        ->whereHas('recipients', fn ($recipients) => $recipients
                                            ->where('user_id', $userId)
                                            ->where('response_status', 'pending'));
                                })
                                ->orWhere(function ($attendance) use ($userId, $attendanceDispatchStatuses): void {
                                    $attendance
                                        ->whereIn('status', $attendanceDispatchStatuses)
                                        ->whereHas('recipients', fn ($recipients) => $recipients
                                            ->where('user_id', $userId)
                                            ->whereIn('response_status', ['pending', 'accepted']));
                                });
                        })
                        ->with(['recipients' => fn ($recipients) => $recipients->where('user_id', $userId)])
                        ->latest(),
                ])
                ->where(function ($query) use ($userId, $attendanceDispatchStatuses): void {
                    $query
                        ->where(function ($normalDeployment) use ($userId, $attendanceDispatchStatuses): void {
                            $normalDeployment
                                ->whereNotIn('status', ['resolved', 'cancelled'])
                                ->where('is_test', false)
                                ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                                    ->where(function ($dispatchQuery) use ($userId, $attendanceDispatchStatuses): void {
                                        $dispatchQuery
                                            ->where(function ($preannouncement) use ($userId): void {
                                                $preannouncement
                                                    ->where('status', 'draft')
                                                    ->whereHas('recipients', fn ($recipients) => $recipients
                                                        ->where('user_id', $userId)
                                                        ->where('response_status', 'pending'));
                                            })
                                            ->orWhere(function ($attendance) use ($userId, $attendanceDispatchStatuses): void {
                                                $attendance
                                                    ->whereIn('status', $attendanceDispatchStatuses)
                                                    ->whereHas('recipients', fn ($recipients) => $recipients
                                                        ->where('user_id', $userId)
                                                        ->whereIn('response_status', ['pending', 'accepted']));
                                            });
                                    }));
                        })
                        ->orWhere(function ($testDeployment) use ($userId): void {
                            $testDeployment
                                ->whereNotIn('status', ['resolved', 'cancelled'])
                                ->where('is_test', true)
                                ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                                    ->whereIn('status', ['draft', 'sent', 'escalated'])
                                    ->whereHas('recipients', fn ($recipients) => $recipients
                                        ->where('user_id', $userId)
                                        ->where('response_status', 'pending')));
                        })
                        ->orWhere(function ($closedDeployment) use ($userId, $attendanceDispatchStatuses): void {
                            $closedDeployment
                                ->whereIn('status', ['resolved', 'cancelled'])
                                ->where('is_test', false)
                                ->whereDoesntHave('pilotReports', fn ($reports) => $reports
                                    ->where('user_id', $userId)
                                    ->whereNotNull('finalized_at'))
                                ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                                    ->whereIn('status', $attendanceDispatchStatuses)
                                    ->whereHas('recipients', fn ($recipients) => $recipients
                                        ->where('user_id', $userId)
                                        ->where('response_status', 'accepted')));
                        });
                })
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (Deployment $deployment): array => $this->deploymentPayloadForActor($deployment, $request->user()))
                ->values();

            return ApiResponse::success($deployments);
        }

        if (! $request->has('per_page')) {
            $deployments = $this->deployments
                ->search($request->only(['status', 'priority']), 100)
                ->getCollection()
                ->map(fn (Deployment $deployment): array => MobileApiPayload::deployment(
                    $deployment,
                    $request->user(),
                    $this->deploymentRequestService,
                ))
                ->values();

            return ApiResponse::success($deployments);
        }

        return ApiResponse::paginated(
            $this->deployments->search($request->only(['status', 'priority']), (int) $request->integer('per_page', 25)),
            fn (Deployment $deployment): array => MobileApiPayload::deployment(
                $deployment,
                $request->user(),
                $this->deploymentRequestService,
            ),
        );
    }

    public function store(StoreDeploymentRequest $request): JsonResponse
    {
        return ApiResponse::error(
            'deployment_request_required',
            'Maak eerst een aanvraagdossier aan en bereid daaruit een conceptinzet voor.',
            409,
        );
    }

    public function flightContextPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_label' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return ApiResponse::success($this->droneFlightContextService->preview(
                (float) $data['latitude'],
                (float) $data['longitude'],
                $data['location_label'] ?? null,
            ));
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::success([
                'generated_at' => ApiDateTime::now(),
                'location' => [
                    'label' => $data['location_label'] ?? null,
                    'latitude' => round((float) $data['latitude'], 7),
                    'longitude' => round((float) $data['longitude'], 7),
                ],
                'map' => [
                    'provider' => 'Aeret Drone PreFlight',
                    'status' => 'unavailable',
                    'aeret_url' => null,
                    'openstreetmap_url' => null,
                    'errors' => ['Dronekaart kon niet worden opgebouwd.'],
                ],
                'airspace' => [
                    'provider' => 'Aeret Drone PreFlight',
                    'status' => 'unavailable',
                    'summary' => 'Drone vluchtcheck kon niet worden opgehaald. Controleer Aeret handmatig.',
                    'no_fly_zones' => [],
                    'notams' => [],
                    'restrictions' => [],
                    'errors' => ['Aeret/NOTAM gegevens konden niet worden opgehaald.'],
                ],
                'weather' => [
                    'provider' => 'Open-Meteo',
                    'status' => 'unavailable',
                    'summary' => 'Weerdata kon niet worden opgehaald.',
                    'errors' => ['Weerdata kon niet worden opgehaald.'],
                ],
                'checklist' => [],
            ]);
        }
    }

    public function show(Request $request, Deployment $deployment): JsonResponse
    {
        $this->access->assertCanViewDeployment($request->user(), $deployment);

        $payload = $this->deploymentPayloadForActor(
            $deployment->load(['coordinator', 'team', 'teams', 'deploymentRequest.workflowRevision']),
            $request->user(),
        );

        return ApiResponse::success($payload);
    }

    public function update(UpdateDeploymentRequest $request, Deployment $deployment): JsonResponse
    {
        $data = $request->validated();
        $updated = DB::transaction(function () use ($deployment, $data, $request): Deployment {
            $this->deploymentRequestService->lockForDeploymentUpdate($deployment);
            $currentDeployment = Deployment::query()->lockForUpdate()->findOrFail($deployment->id);
            $normalizedData = $this->deploymentRequestService->mirrorLegacyDeploymentFields($data, $currentDeployment);
            $this->deploymentRequestService->assertLinkedDecisionFieldsUnchanged($currentDeployment, $normalizedData);
            $updated = $this->service->update(
                $currentDeployment,
                $normalizedData,
                $request->user(),
            );

            return $updated;
        })->load('deploymentRequest.workflowRevision');
        $warnings = $this->service->lastDispatchWarnings();

        return ApiResponse::success(
            MobileApiPayload::deployment($updated, $request->user(), $this->deploymentRequestService),
            200,
            $warnings === [] ? [] : ['warnings' => $warnings],
        );
    }

    public function internalNotes(Deployment $deployment): JsonResponse
    {
        return ApiResponse::success($this->service->internalNotes($deployment));
    }

    public function updateInternalNotes(Request $request, Deployment $deployment): JsonResponse
    {
        $data = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:20000'],
        ]);

        return ApiResponse::success($this->service->updateInternalNotes(
            $deployment,
            $request->user(),
            $data['internal_notes'] ?? null,
        ));
    }

    public function destroy(Request $request, Deployment $deployment): Response
    {
        DB::transaction(function () use ($deployment, $request): void {
            // Keep the same deployment-request -> deployment lock order as linked updates.
            $this->deploymentRequestService->lockForDeploymentUpdate($deployment);
            $currentDeployment = Deployment::query()->lockForUpdate()->findOrFail($deployment->id);
            $this->service->delete($currentDeployment, $request->user());
        });

        return response()->noContent();
    }

    public function refreshFlightContext(Request $request, Deployment $deployment): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::deployment(
            $this->droneFlightContextService->refreshDeployment($deployment),
            $request->user(),
            $this->deploymentRequestService,
        ));
    }

    public function close(Request $request, Deployment $deployment): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success(MobileApiPayload::deployment(
            $this->service->close($deployment, $request->user(), $request->input('reason')),
            $request->user(),
            $this->deploymentRequestService,
        ));
    }

    public function cancel(Request $request, Deployment $deployment): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success(MobileApiPayload::deployment(
            $this->service->cancel($deployment, $request->user(), $request->input('reason')),
            $request->user(),
            $this->deploymentRequestService,
        ));
    }

    public function timeline(Request $request, Deployment $deployment): JsonResponse
    {
        $this->access->assertCanViewDeployment($request->user(), $deployment);
        if ($request->user()->isOperatorClient() && $this->access->relevantDispatch($deployment, $request->user())?->status === 'draft') {
            return ApiResponse::success([]);
        }

        $dispatchQuery = $deployment->dispatchRequests()
            ->with([
                'recipients' => fn ($recipients) => $request->user()->isOperatorClient()
                    ? $recipients->where('user_id', $request->user()->id)
                    : $recipients,
                'recipients.user',
                'messages.sender',
            ])
            ->latest();
        $this->access->scopeDispatches($dispatchQuery, $request->user());
        $dispatches = $dispatchQuery->get();

        $statusItems = $deployment->statusHistory()
            ->with('deployment')
            ->latest('created_at')
            ->get()
            ->map(function ($item): array {
                $statusChange = trim(($item->from_status ?? 'nieuw').' -> '.$item->to_status);

                return [
                    'id' => $item->id,
                    'type' => 'status',
                    'label' => $statusChange,
                    'message' => $item->reason,
                    'created_at' => MobileApiPayload::dateTime($item->created_at),
                    ...DeploymentTimelineAttribution::make(
                        $item->changed_by,
                        $item->changed_by_name,
                        'Inzetstatus gewijzigd: '.$statusChange,
                        'Niet vastgelegd',
                    ),
                    ...DeploymentTimelineVisibility::everyone(),
                ];
            });

        $dispatchItems = $dispatches
            ->flatMap(function ($dispatch): array {
                $items = [[
                    'id' => $dispatch->id,
                    'type' => 'dispatch',
                    'label' => 'Dispatch '.$dispatch->status,
                    'message' => $dispatch->message,
                    'created_at' => MobileApiPayload::dateTime($dispatch->created_at),
                    ...DeploymentTimelineAttribution::make(
                        $dispatch->requested_by,
                        $dispatch->requested_by_name,
                        'Alarmering aangemaakt',
                        'Niet vastgelegd',
                    ),
                    ...DeploymentTimelineVisibility::everyone(),
                ]];

                foreach ($dispatch->recipients as $recipient) {
                    $recipientName = $recipient->user?->name ?? $recipient->user_name ?? 'Verwijderde gebruiker';
                    $responseState = DeploymentTimelineResponsePresentation::currentState($recipient, $dispatch);
                    $items[] = [
                        'id' => $recipient->id,
                        'type' => 'dispatch_response',
                        'label' => $recipientName.' - '.$responseState['response_label'],
                        'message' => $recipient->response_note,
                        'created_at' => MobileApiPayload::dateTime($responseState['occurred_at']),
                        'actor' => $responseState['actor'],
                        'actor_name' => $responseState['actor_name'],
                        'description' => $responseState['description'],
                        ...DeploymentTimelineVisibility::user($recipient->user_id),
                    ];
                }

                foreach ($dispatch->messages as $message) {
                    $senderName = $message->sender?->name ?? $message->sent_by_name;
                    $items[] = [
                        'id' => $message->id,
                        'type' => 'dispatch_message',
                        'label' => 'Nadere info'.($senderName ? ' - '.$senderName : ''),
                        'message' => $message->body,
                        'created_at' => MobileApiPayload::dateTime($message->created_at),
                        ...DeploymentTimelineAttribution::make(
                            $message->sent_by,
                            $senderName,
                            'Nadere informatie toegevoegd',
                            'Niet vastgelegd',
                        ),
                        ...DeploymentTimelineVisibility::everyone(),
                    ];
                }

                return $items;
            });

        $recipientStartsByUser = $dispatches
            ->flatMap(fn ($dispatch) => $dispatch->recipients->map(fn ($recipient): array => [
                'user_id' => $recipient->user_id,
                'started_at' => $recipient->responded_at ?? $recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at,
            ]))
            ->filter(fn (array $recipient): bool => $recipient['started_at'] !== null)
            ->groupBy('user_id')
            ->map(fn ($recipients) => $recipients->pluck('started_at')->min());

        $operatorStatusItems = collect();
        if ($recipientStartsByUser->isNotEmpty()) {
            $firstRelevantStatusAt = $recipientStartsByUser->min();
            $operatorStatusItems = AvailabilityStatus::query()
                ->with('user')
                ->whereIn('user_id', $recipientStartsByUser->keys())
                ->whereIn('status', ['en_route', 'on_scene'])
                ->where('effective_at', '>=', $firstRelevantStatusAt)
                ->latest('effective_at')
                ->get()
                ->filter(fn (AvailabilityStatus $status): bool => $status->effective_at?->greaterThanOrEqualTo($recipientStartsByUser->get($status->user_id)) === true)
                ->map(function (AvailabilityStatus $status): array {
                    $userName = $status->user?->name ?? $status->user_name ?? 'Verwijderde gebruiker';
                    $statusLabel = $this->operatorStatusLabel($status->status);

                    return [
                        'id' => $status->id,
                        'type' => 'operator_status',
                        'label' => $userName.' - '.$statusLabel,
                        'message' => $status->reason,
                        'created_at' => MobileApiPayload::dateTime($status->effective_at),
                        ...DeploymentTimelineAttribution::make(
                            $status->changed_by,
                            $status->changed_by_name,
                            'Operationele status van '.$userName.' gewijzigd naar '.$statusLabel,
                            $status->is_system_applied ? 'Systeem' : 'Niet vastgelegd',
                        ),
                        ...DeploymentTimelineVisibility::user($status->user_id),
                    ];
                });
        }

        $internalNoteLogs = AuditLog::query()
            ->where('target_type', Deployment::class)
            ->where('target_id', (string) $deployment->id)
            ->where('action', 'deployments.internal_note_added')
            ->latest('created_at')
            ->limit(200)
            ->get();
        $operator = $request->user()->isOperatorClient() ? $request->user() : null;
        $operatorRecipientIds = $operator === null
            ? []
            : $dispatches
                ->flatMap(fn ($dispatch) => $dispatch->recipients->pluck('id'))
                ->filter(fn (mixed $recipientId): bool => is_string($recipientId) && $recipientId !== '')
                ->unique()
                ->values()
                ->all();
        $deploymentAuditLogs = $this->deploymentAuditTimelineLogs(
            $deployment,
            $dispatches->pluck('id')->values()->all(),
            $operator,
            $operatorRecipientIds,
        );
        $auditActors = User::query()
            ->withTrashed()
            ->whereIn(
                'id',
                $internalNoteLogs
                    ->concat($deploymentAuditLogs)
                    ->pluck('actor_id')
                    ->filter(fn (mixed $actorId): bool => is_string($actorId) && $actorId !== '')
                    ->unique()
                    ->values(),
            )
            ->get(['id', 'name'])
            ->keyBy('id');

        $internalNoteItems = $internalNoteLogs
            ->map(function (AuditLog $log) use ($auditActors): array {
                $actorName = $this->auditActorName($log, $auditActors);

                return [
                    'id' => $log->id,
                    'type' => 'internal_notes',
                    'label' => 'Meldkamer kladblok',
                    'message' => $log->reason,
                    'created_at' => MobileApiPayload::dateTime($log->created_at),
                    ...DeploymentTimelineAttribution::make(
                        $log->actor_id,
                        $actorName,
                        'Kladblokregel toegevoegd',
                        'Niet vastgelegd',
                    ),
                    ...DeploymentTimelineVisibility::staff(),
                ];
            });

        $legacyInternalNoteItem = collect($deployment->internal_notes === null || trim((string) $deployment->internal_notes) === '' ? [] : [[
            'id' => $deployment->id.'-internal-notes',
            'type' => 'internal_notes',
            'label' => 'Meldkamer kladblok',
            'message' => $deployment->internal_notes,
            'created_at' => MobileApiPayload::dateTime($deployment->updated_at),
            ...DeploymentTimelineAttribution::make(
                null,
                null,
                'Historische kladblokregel',
                'Niet vastgelegd',
            ),
            ...DeploymentTimelineVisibility::staff(),
        ]]);

        $auditItems = $deploymentAuditLogs
            ->map(function (AuditLog $log) use ($auditActors, $dispatches): array {
                $label = $this->auditActionLabel($log->action);
                $description = DeploymentTimelineResponsePresentation::auditDescription($log, $dispatches) ?? $label;

                return [
                    'id' => $log->id,
                    'type' => 'audit',
                    'label' => $label,
                    'message' => $log->reason,
                    'created_at' => MobileApiPayload::dateTime($log->created_at),
                    ...DeploymentTimelineAttribution::make(
                        $log->actor_id,
                        $this->auditActorName($log, $auditActors),
                        $description,
                        $this->auditActorFallback($log),
                    ),
                    ...DeploymentTimelineVisibility::audit($log, $dispatches),
                ];
            });

        $items = $statusItems
            ->concat($dispatchItems)
            ->concat($operatorStatusItems)
            ->concat($internalNoteItems)
            ->concat($legacyInternalNoteItem)
            ->concat($auditItems)
            ->sortByDesc('created_at')
            ->values();

        $actor = $request->user();
        if ($actor->isOperatorClient()) {
            $visibleTypes = $this->appVisibleTimelineTypes();
            $items = $items
                ->filter(fn (array $item): bool => in_array((string) $item['type'], $visibleTypes, true)
                    && DeploymentTimelineVisibility::visibleToOperator($item, (string) $actor->id))
                ->values();
        } elseif ($actor->hasPermission('deployments.manage') !== true) {
            $visibleTypes = $this->appVisibleTimelineTypes();
            $items = $items
                ->filter(fn (array $item): bool => in_array((string) $item['type'], $visibleTypes, true))
                ->values();
        }

        return ApiResponse::success(
            $items
                ->map(fn (array $item): array => DeploymentTimelineVisibility::withoutInternalMetadata($item))
                ->values(),
        );
    }

    /**
     * @return list<string>
     */
    private function appVisibleTimelineTypes(): array
    {
        $value = SystemSetting::value('deployment.timeline.app_visible_types', self::DEFAULT_APP_VISIBLE_TIMELINE_TYPES);
        if (! is_array($value)) {
            return self::DEFAULT_APP_VISIBLE_TIMELINE_TYPES;
        }

        return array_values(array_intersect(self::APP_VISIBLE_TIMELINE_TYPES, array_filter($value, 'is_string')));
    }

    /**
     * @param  list<string>  $dispatchIds
     * @param  list<string>  $operatorRecipientIds
     */
    private function deploymentAuditTimelineLogs(
        Deployment $deployment,
        array $dispatchIds,
        ?User $operator = null,
        array $operatorRecipientIds = [],
    ): Collection {
        $deploymentId = (string) $deployment->id;

        $query = AuditLog::query()
            ->where(function ($query) use ($deploymentId, $dispatchIds): void {
                $query
                    ->where(function ($target) use ($deploymentId): void {
                        $target->where('target_type', Deployment::class)->where('target_id', $deploymentId);
                    })
                    ->orWhere(function ($metadata) use ($deploymentId): void {
                        $metadata->where('metadata->deployment_id', $deploymentId);
                    });

                if ($dispatchIds !== []) {
                    $query->orWhere(function ($dispatches) use ($dispatchIds): void {
                        $dispatches->where('target_type', DispatchRequest::class)->whereIn('target_id', $dispatchIds);
                    });
                }
            })
            ->whereNotIn('action', [
                'deployments.created',
                'deployments.internal_note_added',
                'deployments.status_auto_updated',
                'dispatch.created',
                'dispatch.additional_info_sent',
                'deployments.internal_notes_updated',
            ]);

        if ($operator !== null) {
            DeploymentTimelineVisibility::scopeAuditQueryForOperator(
                $query,
                (string) $operator->id,
                $operatorRecipientIds,
            );
        }

        return $query
            ->latest('created_at')
            ->limit(200)
            ->get();
    }

    /**
     * @param  Collection<string, User>  $actors
     */
    private function auditActorName(AuditLog $log, Collection $actors): ?string
    {
        $snapshotName = trim((string) $log->actor_name);
        if ($snapshotName !== '') {
            return $snapshotName;
        }

        if (! is_string($log->actor_id) || $log->actor_id === '') {
            return null;
        }

        $actor = $actors->get($log->actor_id);

        return $actor instanceof User ? $actor->name : null;
    }

    private function auditActionLabel(string $action): string
    {
        return match ($action) {
            'deployments.updated' => 'Inzet bijgewerkt',
            'deployments.deleted' => 'Inzet verwijderd',
            'deployments.status_auto_updated' => 'Inzetstatus automatisch bijgewerkt',
            'deployments.preannouncement_sent' => 'Vooraankondiging verstuurd',
            'deployments.active_cancelled_notification_sent' => 'Annulering verstuurd',
            'deployments.internal_notes_updated' => 'Meldkamer kladblok bijgewerkt',
            'deployments.internal_note_added' => 'Meldkamer kladblok',
            'dispatch.created' => 'Alarmeringsconcept gemaakt',
            'dispatch.sent' => 'Alarmering verstuurd',
            'dispatch.responded' => 'Reactie verwerkt',
            'dispatch.recipient_response_overridden' => 'Reactie aangepast',
            'dispatch.additional_info_sent' => 'Nadere info verstuurd',
            'dispatch.escalated' => 'Opgeschaald',
            'dispatch.realerted' => 'Heralarmering verstuurd',
            'location.share_requested' => 'Live locatie gevraagd',
            'location.sharing_stopped_for_deployment' => 'Live locatie gestopt',
            'location.consent_enabled' => 'Live locatie toegestaan',
            'location.consent_declined' => 'Live locatie geweigerd',
            'location.consent_revoked' => 'Live locatie ingetrokken',
            'pilot_deployment_report.prepared' => 'Inzetrapport klaargezet',
            'pilot_deployment_report.opened_by_admin' => 'Inzetrapport geopend door beheerder',
            'pilot_deployment_report.submitted' => 'Inzetrapport ingediend',
            'pilot_deployment_report.submitted_by_admin' => 'Inzetrapport namens gebruiker ingediend',
            'pilot_deployment_report.finalized' => 'Inzetrapport definitief gemaakt',
            'pilot_deployment_report.finalized_by_admin' => 'Inzetrapport namens gebruiker definitief gemaakt',
            default => str_replace('_', ' ', str_replace('.', ' - ', $action)),
        };
    }

    public function dispatchPreview(Request $request, Deployment $deployment): JsonResponse
    {
        $data = $request->validate([
            'dispatch_recipient_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'include_unavailable' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success($this->dispatchService->previewForDeployment($deployment, $data));
    }

    private function operatorStatusLabel(string $status): string
    {
        return match ($status) {
            'en_route' => 'Onderweg',
            'on_scene' => 'Op locatie',
            default => $status,
        };
    }

    private function auditActorFallback(AuditLog $log): string
    {
        return in_array($log->action, ['deployments.status_auto_updated'], true)
            ? 'Systeem'
            : 'Niet vastgelegd';
    }

    /**
     * @return array<string, mixed>
     */
    private function deploymentPayloadForActor(Deployment $deployment, User $actor): array
    {
        $payload = MobileApiPayload::deployment($deployment, $actor, $this->deploymentRequestService);
        if (! $actor->isOperatorClient()) {
            return $payload;
        }

        $dispatch = $deployment->relationLoaded('dispatchRequests')
            ? $deployment->dispatchRequests->first()
            : $this->access->relevantDispatch($deployment, $actor);
        $recipient = $dispatch?->relationLoaded('recipients') === true
            ? $dispatch->recipients->firstWhere('user_id', $actor->id)
            : $dispatch?->recipients()->where('user_id', $actor->id)->first();

        if ($dispatch?->status === 'draft') {
            $place = $this->dispatchService->placeNameFromLocation($deployment->location_label);
            $payload = [
                'id' => $deployment->id,
                'reference' => 'Vooraankondiging',
                'title' => $place === null ? 'Beschikbaar voor een mogelijke inzet?' : "Beschikbaar voor een mogelijke inzet in {$place}?",
                'description' => null,
                'reporter_name' => null,
                'reporter_phone' => null,
                'requesting_organization' => null,
                'requesting_unit' => null,
                'on_scene_contact_name' => null,
                'on_scene_contact_phone' => null,
                'on_scene_contact_role' => null,
                'required_resources' => null,
                'custom_fields' => (object) [],
                'deployment_request' => null,
                'priority' => 'normal',
                'status' => $deployment->status,
                'is_test' => (bool) $deployment->is_test,
                'location_label' => $place,
                'latitude' => null,
                'longitude' => null,
                'drone_flight_context' => null,
                'coordinator' => null,
                'team' => null,
                'teams' => [],
                'opened_at' => MobileApiPayload::dateTime($deployment->opened_at),
                'closed_at' => MobileApiPayload::dateTime($deployment->closed_at),
            ];
        }

        $payload['active_dispatch'] = $dispatch === null ? null : [
            'id' => $dispatch->id,
            'status' => $dispatch->status,
            'response_status' => $recipient?->response_status,
        ];

        return $payload;
    }
}
