<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AvailabilityStatus;
use App\Models\Incident;
use App\Repositories\IncidentRepository;
use App\Services\DispatchService;
use App\Services\DroneFlightContextService;
use App\Services\IncidentService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly IncidentService $service,
        private readonly DispatchService $dispatchService,
        private readonly DroneFlightContextService $droneFlightContextService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('active_alarms')) {
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
                ->map(function (Incident $incident): array {
                    $payload = MobileApiPayload::incident($incident);
                    $dispatch = $incident->dispatchRequests->first();
                    $recipient = $dispatch?->recipients->first();
                    if ($dispatch?->status === 'draft') {
                        $place = $this->placeNameFromLocation($incident->location_label);
                        $payload['reference'] = 'Vooraankondiging';
                        $payload['title'] = $place === null
                            ? 'Beschikbaar voor melding?'
                            : "Beschikbaar voor melding in {$place}?";
                        $payload['description'] = null;
                        $payload['location_label'] = $place;
                        $payload['priority'] = 'normal';
                    }
                    $payload['active_dispatch'] = $dispatch === null ? null : [
                        'id' => $dispatch->id,
                        'status' => $dispatch->status,
                        'response_status' => $recipient?->response_status,
                    ];

                    return $payload;
                })
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
                'generated_at' => now()->toIso8601String(),
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
                    'errors' => [$exception->getMessage()],
                ],
                'airspace' => [
                    'provider' => 'Aeret Drone PreFlight',
                    'status' => 'unavailable',
                    'summary' => 'Drone vluchtcheck kon niet worden opgehaald. Controleer Aeret handmatig.',
                    'no_fly_zones' => [],
                    'notams' => [],
                    'restrictions' => [],
                    'errors' => [$exception->getMessage()],
                ],
                'weather' => [
                    'provider' => 'Open-Meteo',
                    'status' => 'unavailable',
                    'summary' => 'Weerdata kon niet worden opgehaald.',
                    'errors' => [$exception->getMessage()],
                ],
                'checklist' => [],
            ]);
        }
    }

    public function show(Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::incident($incident->load(['coordinator', 'team', 'teams'])));
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::incident($this->service->update($incident, $request->validated(), $request->user())));
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

    public function timeline(Incident $incident): JsonResponse
    {
        $dispatches = $incident->dispatchRequests()
            ->with(['recipients.user', 'messages.sender'])
            ->latest()
            ->get();

        $statusItems = $incident->statusHistory()
            ->with('incident')
            ->latest('created_at')
            ->get()
            ->map(fn ($item): array => [
                'id' => $item->id,
                'type' => 'status',
                'label' => trim(($item->from_status ?? 'nieuw').' -> '.$item->to_status),
                'message' => $item->reason,
                'created_at' => MobileApiPayload::dateTime($item->created_at),
            ]);

        $dispatchItems = $dispatches
            ->flatMap(function ($dispatch): array {
                $items = [[
                    'id' => $dispatch->id,
                    'type' => 'dispatch',
                    'label' => 'Dispatch '.$dispatch->status,
                    'message' => $dispatch->message,
                    'created_at' => MobileApiPayload::dateTime($dispatch->created_at),
                ]];

                foreach ($dispatch->recipients as $recipient) {
                    $items[] = [
                        'id' => $recipient->id,
                        'type' => 'dispatch_response',
                        'label' => ($recipient->user?->name ?? $recipient->user_name ?? 'Verwijderde gebruiker').' - '.$recipient->response_status,
                        'message' => $recipient->response_note,
                        'created_at' => MobileApiPayload::dateTime($recipient->responded_at ?? $recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at),
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
                ->map(fn (AvailabilityStatus $status): array => [
                    'id' => $status->id,
                    'type' => 'operator_status',
                    'label' => ($status->user?->name ?? $status->user_name ?? 'Verwijderde gebruiker').' - '.$this->operatorStatusLabel($status->status),
                    'message' => $status->reason,
                    'created_at' => MobileApiPayload::dateTime($status->effective_at),
                ]);
        }

        return ApiResponse::success($statusItems->concat($dispatchItems)->concat($operatorStatusItems)->sortByDesc('created_at')->values());
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
}
