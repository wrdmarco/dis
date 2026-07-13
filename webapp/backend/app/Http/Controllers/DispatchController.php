<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dispatch\RespondDispatchRequest;
use App\Http\Requests\Dispatch\StoreDispatchRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Services\DispatchService;
use App\Services\IncidentAccessService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DispatchController extends Controller
{
    public function __construct(
        private readonly DispatchService $service,
        private readonly IncidentAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DispatchRequest::query()
            ->with(['incident', 'recipients'])
            ->when(! $request->boolean('include_tests'), fn ($query) => $query->whereHas('incident', fn ($incident) => $incident->where('is_test', false)))
            ->latest();
        $this->access->scopeDispatches($query, $request->user());

        return ApiResponse::paginated(
            $query->paginate((int) $request->integer('per_page', 25)),
            fn (DispatchRequest $dispatch): array => $this->dispatchPayloadForActor($dispatch, $request->user()),
        );
    }

    public function store(StoreDispatchRequest $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::dispatch($this->service->create($incident, $request->validated(), $request->user())->load(['incident', 'targetTeam', 'recipients.user'])), 201);
    }

    public function show(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $this->access->assertCanViewDispatch($request->user(), $dispatch);
        $dispatch->load([
            'incident',
            'recipients' => fn ($recipients) => $request->user()->isOperatorClient()
                ? $recipients->where('user_id', $request->user()->id)
                : $recipients,
            'recipients.user',
        ]);

        return ApiResponse::success($this->dispatchPayloadForActor($dispatch, $request->user()));
    }

    public function send(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::dispatch($this->service->markSent($dispatch, $request->user())->load(['incident', 'targetTeam', 'recipients.user'])));
    }

    public function message(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return ApiResponse::success($this->service->sendAdditionalInfo($dispatch, $request->user(), $data['message']));
    }

    public function respond(RespondDispatchRequest $request, DispatchRequest $dispatch): Response
    {
        $this->service->respond($dispatch, $request->user(), $request->validated('response'), $request->validated('note'));

        return response()->noContent();
    }

    public function overrideRecipientResponse(Request $request, DispatchRequest $dispatch, DispatchRecipient $recipient): JsonResponse
    {
        $data = $request->validate([
            'response' => ['required', 'in:pending,accepted,declined,no_response'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return ApiResponse::success(MobileApiPayload::dispatchRecipient($this->service->overrideRecipientResponse(
            $dispatch,
            $recipient,
            $request->user(),
            $data['response'],
            $data['note'] ?? null,
        )->load('user')));
    }

    public function cancel(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $dispatch->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->service->broadcastDispatchChange($dispatch->refresh(), 'cancelled');

        return ApiResponse::success(MobileApiPayload::dispatch($dispatch->load(['incident', 'targetTeam', 'recipients.user'])));
    }

    public function escalate(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $data = $request->validate([
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['ulid', 'exists:teams,id'],
            'include_unavailable' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success(MobileApiPayload::dispatch($this->service->escalate(
            $dispatch,
            $request->user(),
            $data['team_ids'] ?? [],
            $request->boolean('include_unavailable'),
        )->load(['incident', 'targetTeam', 'recipients.user'])));
    }

    public function reAlert(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::dispatch($this->service->reAlert($dispatch, $request->user())->load(['incident', 'targetTeam', 'recipients.user'])));
    }

    public function recipients(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $this->access->assertCanViewDispatch($request->user(), $dispatch);

        return ApiResponse::success($dispatch->recipients()->with([
            'user',
            'user.statuses' => fn ($statuses) => $statuses->latestPerUser(),
        ])->when($request->user()->isOperatorClient(), fn ($recipients) => $recipients->where('user_id', $request->user()->id))
            ->get()
            ->map(fn (DispatchRecipient $recipient): array => MobileApiPayload::dispatchRecipient($recipient))
            ->values());
    }

    public function incidentDispatches(Request $request, Incident $incident): JsonResponse
    {
        $this->access->assertCanViewIncident($request->user(), $incident);
        $query = $incident->dispatchRequests()
            ->with([
                'targetTeam',
                'incident',
                'recipients' => fn ($recipients) => $request->user()->isOperatorClient()
                    ? $recipients->where('user_id', $request->user()->id)
                    : $recipients,
                'recipients.user',
                'recipients.user.statuses' => fn ($statuses) => $statuses->latestPerUser(),
            ])
            ->latest();
        $this->access->scopeDispatches($query, $request->user());

        return ApiResponse::success($query->get()
            ->map(fn (DispatchRequest $dispatch): array => $this->dispatchPayloadForActor($dispatch, $request->user()))
            ->values());
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchPayloadForActor(DispatchRequest $dispatch, \App\Models\User $actor): array
    {
        $payload = MobileApiPayload::dispatch($dispatch);
        if (! $actor->isOperatorClient() || $dispatch->status !== 'draft' || $dispatch->incident === null) {
            return $payload;
        }

        $place = $this->service->placeNameFromLocation($dispatch->incident->location_label);
        $payload['incident'] = [
            'id' => $dispatch->incident->id,
            'reference' => 'Vooraankondiging',
            'title' => $place === null ? 'Beschikbaar voor melding?' : "Beschikbaar voor melding in {$place}?",
            'description' => null,
            'priority' => 'normal',
            'status' => $dispatch->incident->status,
            'is_test' => (bool) $dispatch->incident->is_test,
            'location_label' => $place,
            'latitude' => null,
            'longitude' => null,
            'custom_fields' => [],
        ];

        return $payload;
    }
}
