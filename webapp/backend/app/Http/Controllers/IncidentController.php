<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AvailabilityStatus;
use App\Models\Incident;
use App\Repositories\IncidentRepository;
use App\Services\DispatchService;
use App\Services\IncidentService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly IncidentService $service,
        private readonly DispatchService $dispatchService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('active_alarms')) {
            $userId = $request->user()->id;
            $activeDispatchStatuses = ['sent', 'escalated'];
            $incidents = Incident::query()
                ->with([
                    'coordinator',
                    'team',
                    'dispatchRequests' => fn ($dispatches) => $dispatches
                        ->whereIn('status', $activeDispatchStatuses)
                        ->whereHas('recipients', fn ($recipients) => $recipients
                            ->where('user_id', $userId)
                            ->whereIn('response_status', ['pending', 'accepted']))
                        ->with(['recipients' => fn ($recipients) => $recipients->where('user_id', $userId)])
                        ->latest(),
                ])
                ->whereNotIn('status', ['resolved', 'cancelled'])
                ->where(function ($query) use ($userId, $activeDispatchStatuses): void {
                    $query
                        ->where(function ($normalIncident) use ($userId, $activeDispatchStatuses): void {
                            $normalIncident
                                ->where('is_test', false)
                                ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                                    ->whereIn('status', $activeDispatchStatuses)
                                    ->whereHas('recipients', fn ($recipients) => $recipients
                                        ->where('user_id', $userId)
                                        ->whereIn('response_status', ['pending', 'accepted'])));
                        })
                        ->orWhere(function ($testIncident) use ($userId, $activeDispatchStatuses): void {
                            $testIncident
                                ->where('is_test', true)
                                ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                                    ->whereIn('status', $activeDispatchStatuses)
                                    ->whereHas('recipients', fn ($recipients) => $recipients
                                        ->where('user_id', $userId)
                                        ->where('response_status', 'pending')));
                        });
                })
                ->latest()
                ->limit(100)
                ->get()
                ->map(function (Incident $incident): array {
                    $payload = MobileApiPayload::incident($incident);
                    $dispatch = $incident->dispatchRequests->first();
                    $recipient = $dispatch?->recipients->first();
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

    public function show(Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::incident($incident->load(['coordinator', 'team'])));
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::incident($this->service->update($incident, $request->validated(), $request->user())));
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
                'created_at' => $item->created_at?->toIso8601String(),
            ]);

        $dispatchItems = $dispatches
            ->flatMap(function ($dispatch): array {
                $items = [[
                    'id' => $dispatch->id,
                    'type' => 'dispatch',
                    'label' => 'Dispatch '.$dispatch->status,
                    'message' => $dispatch->message,
                    'created_at' => $dispatch->created_at?->toIso8601String(),
                ]];

                foreach ($dispatch->recipients as $recipient) {
                    $items[] = [
                        'id' => $recipient->id,
                        'type' => 'dispatch_response',
                        'label' => ($recipient->user?->name ?? 'Onbekende gebruiker').' - '.$recipient->response_status,
                        'message' => $recipient->response_note,
                        'created_at' => ($recipient->responded_at ?? $recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at)?->toIso8601String(),
                    ];
                }

                foreach ($dispatch->messages as $message) {
                    $items[] = [
                        'id' => $message->id,
                        'type' => 'dispatch_message',
                        'label' => 'Nadere info'.($message->sender?->name ? ' - '.$message->sender->name : ''),
                        'message' => $message->body,
                        'created_at' => $message->created_at?->toIso8601String(),
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
                    'label' => ($status->user?->name ?? 'Onbekende gebruiker').' - '.$this->operatorStatusLabel($status->status),
                    'message' => $status->reason,
                    'created_at' => $status->effective_at?->toIso8601String(),
                ]);
        }

        return ApiResponse::success($statusItems->concat($dispatchItems)->concat($operatorStatusItems)->sortByDesc('created_at')->values());
    }

    public function dispatchPreview(Incident $incident): JsonResponse
    {
        return ApiResponse::success($this->dispatchService->previewForIncident($incident));
    }

    private function operatorStatusLabel(string $status): string
    {
        return match ($status) {
            'en_route' => 'Onderweg',
            'on_scene' => 'Op locatie',
            default => $status,
        };
    }
}
