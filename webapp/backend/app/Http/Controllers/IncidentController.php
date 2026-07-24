<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\AvailabilityStatus;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repositories\IncidentRepository;
use App\Services\DispatchService;
use App\Services\DroneFlightContextService;
use App\Services\IncidentAccessService;
use App\Services\IncidentService;
use App\Support\ApiDateTime;
use App\Support\IncidentTimelineAttribution;
use App\Support\IncidentTimelineResponsePresentation;
use App\Support\IncidentTimelineVisibility;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class IncidentController extends Controller
{
    private const DEFAULT_APP_VISIBLE_TIMELINE_TYPES = ['status', 'dispatch', 'dispatch_response', 'dispatch_message', 'operator_status'];

    private const APP_VISIBLE_TIMELINE_TYPES = ['status', 'dispatch', 'dispatch_response', 'dispatch_message', 'operator_status', 'audit'];

    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly IncidentService $service,
        private readonly DispatchService $dispatchService,
        private readonly DroneFlightContextService $droneFlightContextService,
        private readonly IncidentAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanListIncidents($request->user());

        if ($request->user()->isOperatorClient() || $request->boolean('active_alarms')) {
            $userId = $request->user()->id;
            $attendanceDispatchStatuses = ['sent', 'escalated'];
            $incidents = Incident::query()
                ->with([
                    'coordinator',
                    'team',
                    'teams',
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
                        ->where(function ($normalIncident) use ($userId, $attendanceDispatchStatuses): void {
                            $normalIncident
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
                        ->orWhere(function ($testIncident) use ($userId): void {
                            $testIncident
                                ->whereNotIn('status', ['resolved', 'cancelled'])
                                ->where('is_test', true)
                                ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                                    ->whereIn('status', ['draft', 'sent', 'escalated'])
                                    ->whereHas('recipients', fn ($recipients) => $recipients
                                        ->where('user_id', $userId)
                                        ->where('response_status', 'pending')));
                        })
                        ->orWhere(function ($closedIncident) use ($userId, $attendanceDispatchStatuses): void {
                            $closedIncident
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
                ->map(fn (Incident $incident): array => $this->incidentPayloadForActor($incident, $request->user()))
                ->values();

            return ApiResponse::success($incidents);
        }

        if (! $request->has('per_page')) {
            $incidents = $this->incidents
                ->search($request->only(['status', 'priority']), 100)
                ->getCollection()
                ->map(fn (Incident $incident): array => MobileApiPayload::incident($incident))
                ->values();

            return ApiResponse::success($incidents);
        }

        return ApiResponse::paginated(
            $this->incidents->search($request->only(['status', 'priority']), (int) $request->integer('per_page', 25)),
            fn (Incident $incident): array => MobileApiPayload::incident($incident),
        );
    }

    public function store(StoreIncidentRequest $request): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::incident($this->service->create($request->validated(), $request->user())), 201);
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

    public function show(Request $request, Incident $incident): JsonResponse
    {
        $this->access->assertCanViewIncident($request->user(), $incident);

        $payload = $this->incidentPayloadForActor(
            $incident->load(['coordinator', 'team', 'teams']),
            $request->user(),
        );

        return ApiResponse::success($payload);
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): JsonResponse
    {
        $updated = $this->service->update($incident, $request->validated(), $request->user());
        $warnings = $this->service->lastDispatchWarnings();

        return ApiResponse::success(
            MobileApiPayload::incident($updated),
            200,
            $warnings === [] ? [] : ['warnings' => $warnings],
        );
    }

    public function internalNotes(Incident $incident): JsonResponse
    {
        return ApiResponse::success($this->service->internalNotes($incident));
    }

    public function updateInternalNotes(Request $request, Incident $incident): JsonResponse
    {
        $data = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:20000'],
        ]);

        return ApiResponse::success($this->service->updateInternalNotes(
            $incident,
            $request->user(),
            $data['internal_notes'] ?? null,
        ));
    }

    public function destroy(Request $request, Incident $incident): Response
    {
        $this->service->delete($incident, $request->user());

        return response()->noContent();
    }

    public function refreshFlightContext(Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::incident($this->droneFlightContextService->refreshIncident($incident)));
    }

    public function close(Request $request, Incident $incident): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success(MobileApiPayload::incident($this->service->close($incident, $request->user(), $request->input('reason'))));
    }

    public function cancel(Request $request, Incident $incident): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success(MobileApiPayload::incident($this->service->cancel($incident, $request->user(), $request->input('reason'))));
    }

    public function timeline(Request $request, Incident $incident): JsonResponse
    {
        $this->access->assertCanViewIncident($request->user(), $incident);
        if ($request->user()->isOperatorClient() && $this->access->relevantDispatch($incident, $request->user())?->status === 'draft') {
            return ApiResponse::success([]);
        }

        $dispatchQuery = $incident->dispatchRequests()
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

        $statusItems = $incident->statusHistory()
            ->with('incident')
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
                    ...IncidentTimelineAttribution::make(
                        $item->changed_by,
                        $item->changed_by_name,
                        'Incidentstatus gewijzigd: '.$statusChange,
                        'Niet vastgelegd',
                    ),
                    ...IncidentTimelineVisibility::everyone(),
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
                    ...IncidentTimelineAttribution::make(
                        $dispatch->requested_by,
                        $dispatch->requested_by_name,
                        'Alarmering aangemaakt',
                        'Niet vastgelegd',
                    ),
                    ...IncidentTimelineVisibility::everyone(),
                ]];

                foreach ($dispatch->recipients as $recipient) {
                    $recipientName = $recipient->user?->name ?? $recipient->user_name ?? 'Verwijderde gebruiker';
                    $responseState = IncidentTimelineResponsePresentation::currentState($recipient, $dispatch);
                    $items[] = [
                        'id' => $recipient->id,
                        'type' => 'dispatch_response',
                        'label' => $recipientName.' - '.$responseState['response_label'],
                        'message' => $recipient->response_note,
                        'created_at' => MobileApiPayload::dateTime($responseState['occurred_at']),
                        'actor' => $responseState['actor'],
                        'actor_name' => $responseState['actor_name'],
                        'description' => $responseState['description'],
                        ...IncidentTimelineVisibility::user($recipient->user_id),
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
                        ...IncidentTimelineAttribution::make(
                            $message->sent_by,
                            $senderName,
                            'Nadere informatie toegevoegd',
                            'Niet vastgelegd',
                        ),
                        ...IncidentTimelineVisibility::everyone(),
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
                        ...IncidentTimelineAttribution::make(
                            $status->changed_by,
                            $status->changed_by_name,
                            'Operationele status van '.$userName.' gewijzigd naar '.$statusLabel,
                            $status->is_system_applied ? 'Systeem' : 'Niet vastgelegd',
                        ),
                        ...IncidentTimelineVisibility::user($status->user_id),
                    ];
                });
        }

        $internalNoteLogs = AuditLog::query()
            ->where('target_type', Incident::class)
            ->where('target_id', (string) $incident->id)
            ->where('action', 'incidents.internal_note_added')
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
        $incidentAuditLogs = $this->incidentAuditTimelineLogs(
            $incident,
            $dispatches->pluck('id')->values()->all(),
            $operator,
            $operatorRecipientIds,
        );
        $auditActors = User::query()
            ->withTrashed()
            ->whereIn(
                'id',
                $internalNoteLogs
                    ->concat($incidentAuditLogs)
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
                    ...IncidentTimelineAttribution::make(
                        $log->actor_id,
                        $actorName,
                        'Kladblokregel toegevoegd',
                        'Niet vastgelegd',
                    ),
                    ...IncidentTimelineVisibility::staff(),
                ];
            });

        $legacyInternalNoteItem = collect($incident->internal_notes === null || trim((string) $incident->internal_notes) === '' ? [] : [[
            'id' => $incident->id.'-internal-notes',
            'type' => 'internal_notes',
            'label' => 'Meldkamer kladblok',
            'message' => $incident->internal_notes,
            'created_at' => MobileApiPayload::dateTime($incident->updated_at),
            ...IncidentTimelineAttribution::make(
                null,
                null,
                'Historische kladblokregel',
                'Niet vastgelegd',
            ),
            ...IncidentTimelineVisibility::staff(),
        ]]);

        $auditItems = $incidentAuditLogs
            ->map(function (AuditLog $log) use ($auditActors, $dispatches): array {
                $label = $this->auditActionLabel($log->action);
                $description = IncidentTimelineResponsePresentation::auditDescription($log, $dispatches) ?? $label;

                return [
                    'id' => $log->id,
                    'type' => 'audit',
                    'label' => $label,
                    'message' => $log->reason,
                    'created_at' => MobileApiPayload::dateTime($log->created_at),
                    ...IncidentTimelineAttribution::make(
                        $log->actor_id,
                        $this->auditActorName($log, $auditActors),
                        $description,
                        $this->auditActorFallback($log),
                    ),
                    ...IncidentTimelineVisibility::audit($log, $dispatches),
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
                    && IncidentTimelineVisibility::visibleToOperator($item, (string) $actor->id))
                ->values();
        } elseif ($actor->hasPermission('incidents.manage') !== true) {
            $visibleTypes = $this->appVisibleTimelineTypes();
            $items = $items
                ->filter(fn (array $item): bool => in_array((string) $item['type'], $visibleTypes, true))
                ->values();
        }

        return ApiResponse::success(
            $items
                ->map(fn (array $item): array => IncidentTimelineVisibility::withoutInternalMetadata($item))
                ->values(),
        );
    }

    /**
     * @return list<string>
     */
    private function appVisibleTimelineTypes(): array
    {
        $value = SystemSetting::value('incident.timeline.app_visible_types', self::DEFAULT_APP_VISIBLE_TIMELINE_TYPES);
        if (! is_array($value)) {
            return self::DEFAULT_APP_VISIBLE_TIMELINE_TYPES;
        }

        return array_values(array_intersect(self::APP_VISIBLE_TIMELINE_TYPES, array_filter($value, 'is_string')));
    }

    /**
     * @param  list<string>  $dispatchIds
     * @param  list<string>  $operatorRecipientIds
     */
    private function incidentAuditTimelineLogs(
        Incident $incident,
        array $dispatchIds,
        ?User $operator = null,
        array $operatorRecipientIds = [],
    ): Collection {
        $incidentId = (string) $incident->id;

        $query = AuditLog::query()
            ->where(function ($query) use ($incidentId, $dispatchIds): void {
                $query
                    ->where(function ($target) use ($incidentId): void {
                        $target->where('target_type', Incident::class)->where('target_id', $incidentId);
                    })
                    ->orWhere(function ($metadata) use ($incidentId): void {
                        $metadata->where('metadata->incident_id', $incidentId);
                    });

                if ($dispatchIds !== []) {
                    $query->orWhere(function ($dispatches) use ($dispatchIds): void {
                        $dispatches->where('target_type', DispatchRequest::class)->whereIn('target_id', $dispatchIds);
                    });
                }
            })
            ->whereNotIn('action', [
                'incidents.created',
                'incidents.internal_note_added',
                'incidents.status_auto_updated',
                'dispatch.created',
                'dispatch.additional_info_sent',
                'incidents.internal_notes_updated',
            ]);

        if ($operator !== null) {
            IncidentTimelineVisibility::scopeAuditQueryForOperator(
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
            'incidents.updated' => 'Incident bijgewerkt',
            'incidents.deleted' => 'Incident verwijderd',
            'incidents.status_auto_updated' => 'Incidentstatus automatisch bijgewerkt',
            'incidents.preannouncement_sent' => 'Vooraankondiging verstuurd',
            'incidents.active_cancelled_notification_sent' => 'Annulering verstuurd',
            'incidents.internal_notes_updated' => 'Meldkamer kladblok bijgewerkt',
            'incidents.internal_note_added' => 'Meldkamer kladblok',
            'dispatch.created' => 'Alarmeringsconcept gemaakt',
            'dispatch.sent' => 'Alarmering verstuurd',
            'dispatch.responded' => 'Reactie verwerkt',
            'dispatch.recipient_response_overridden' => 'Reactie aangepast',
            'dispatch.additional_info_sent' => 'Nadere info verstuurd',
            'dispatch.escalated' => 'Opgeschaald',
            'dispatch.realerted' => 'Heralarmering verstuurd',
            'location.share_requested' => 'Live locatie gevraagd',
            'location.sharing_stopped_for_incident' => 'Live locatie gestopt',
            'location.consent_enabled' => 'Live locatie toegestaan',
            'location.consent_declined' => 'Live locatie geweigerd',
            'location.consent_revoked' => 'Live locatie ingetrokken',
            'pilot_incident_report.prepared' => 'Inzetrapport klaargezet',
            'pilot_incident_report.opened_by_admin' => 'Inzetrapport geopend door beheerder',
            'pilot_incident_report.submitted' => 'Inzetrapport ingediend',
            'pilot_incident_report.submitted_by_admin' => 'Inzetrapport namens gebruiker ingediend',
            'pilot_incident_report.finalized' => 'Inzetrapport definitief gemaakt',
            'pilot_incident_report.finalized_by_admin' => 'Inzetrapport namens gebruiker definitief gemaakt',
            default => str_replace('_', ' ', str_replace('.', ' - ', $action)),
        };
    }

    public function dispatchPreview(Request $request, Incident $incident): JsonResponse
    {
        $data = $request->validate([
            'dispatch_recipient_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'include_unavailable' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success($this->dispatchService->previewForIncident($incident, $data));
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
        return in_array($log->action, ['incidents.status_auto_updated'], true)
            ? 'Systeem'
            : 'Niet vastgelegd';
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentPayloadForActor(Incident $incident, User $actor): array
    {
        $payload = MobileApiPayload::incident($incident);
        if (! $actor->isOperatorClient()) {
            return $payload;
        }

        $dispatch = $incident->relationLoaded('dispatchRequests')
            ? $incident->dispatchRequests->first()
            : $this->access->relevantDispatch($incident, $actor);
        $recipient = $dispatch?->relationLoaded('recipients') === true
            ? $dispatch->recipients->firstWhere('user_id', $actor->id)
            : $dispatch?->recipients()->where('user_id', $actor->id)->first();

        if ($dispatch?->status === 'draft') {
            $place = $this->dispatchService->placeNameFromLocation($incident->location_label);
            $payload = [
                'id' => $incident->id,
                'reference' => 'Vooraankondiging',
                'title' => $place === null ? 'Beschikbaar voor melding?' : "Beschikbaar voor melding in {$place}?",
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
                'priority' => 'normal',
                'status' => $incident->status,
                'is_test' => (bool) $incident->is_test,
                'location_label' => $place,
                'latitude' => null,
                'longitude' => null,
                'drone_flight_context' => null,
                'coordinator' => null,
                'team' => null,
                'teams' => [],
                'opened_at' => MobileApiPayload::dateTime($incident->opened_at),
                'closed_at' => MobileApiPayload::dateTime($incident->closed_at),
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
