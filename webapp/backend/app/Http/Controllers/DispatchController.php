<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dispatch\RespondDispatchRequest;
use App\Http\Requests\Dispatch\StoreDispatchRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Deployment;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\User;
use App\Services\DeploymentAccessService;
use App\Services\DeploymentRequestService;
use App\Services\DispatchDeliveryStatusService;
use App\Services\DispatchService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DispatchController extends Controller
{
    public function __construct(
        private readonly DispatchService $service,
        private readonly DeploymentAccessService $access,
        private readonly DispatchDeliveryStatusService $deliveryStatus,
        private readonly DeploymentRequestService $deploymentRequestService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanListDispatches($request->user());

        $query = DispatchRequest::query()
            ->with(['deployment', 'recipients'])
            ->when(! $request->boolean('include_tests'), fn ($query) => $query->whereHas('deployment', fn ($deployment) => $deployment->where('is_test', false)))
            ->latest();
        $this->access->scopeDispatches($query, $request->user());

        return ApiResponse::paginated(
            $query->paginate((int) $request->integer('per_page', 25)),
            fn (DispatchRequest $dispatch): array => $this->dispatchPayloadForActor($dispatch, $request->user()),
        );
    }

    public function store(StoreDispatchRequest $request, Deployment $deployment): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::dispatch($this->service->create($deployment, $request->validated(), $request->user())->load(['deployment.deploymentRequest.workflowRevision', 'targetTeam', 'recipients.user']), $request->user(), $this->deploymentRequestService), 201);
    }

    public function show(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $this->access->assertCanViewDispatch($request->user(), $dispatch);
        $dispatch->load([
            'deployment',
            'recipients' => fn ($recipients) => $request->user()->isOperatorClient()
                ? $recipients->where('user_id', $request->user()->id)
                : $recipients,
            'recipients.user',
        ]);

        return ApiResponse::success($this->dispatchPayloadForActor($dispatch, $request->user()));
    }

    public function send(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::dispatch($this->service->markSent($dispatch, $request->user())->load(['deployment.deploymentRequest.workflowRevision', 'targetTeam', 'recipients.user']), $request->user(), $this->deploymentRequestService));
    }

    public function delivery(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $this->access->assertCanViewDispatch($request->user(), $dispatch);

        return ApiResponse::success($this->deliveryStatus->payload($dispatch->refresh()));
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
        $cancelled = $this->service->cancel($dispatch, $request->user());

        return ApiResponse::success(MobileApiPayload::dispatch($cancelled->load(['deployment.deploymentRequest.workflowRevision', 'targetTeam', 'recipients.user']), $request->user(), $this->deploymentRequestService));
    }

    public function escalate(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $data = $request->validate([
            'team_ids' => ['sometimes', 'array', 'max:50'],
            'team_ids.*' => ['ulid', 'exists:teams,id'],
            'include_unavailable' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success(MobileApiPayload::dispatch($this->service->escalate(
            $dispatch,
            $request->user(),
            $data['team_ids'] ?? [],
            $request->boolean('include_unavailable'),
        )->load(['deployment.deploymentRequest.workflowRevision', 'targetTeam', 'recipients.user']), $request->user(), $this->deploymentRequestService));
    }

    public function reAlert(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::dispatch($this->service->reAlert($dispatch, $request->user())->load(['deployment.deploymentRequest.workflowRevision', 'targetTeam', 'recipients.user']), $request->user(), $this->deploymentRequestService));
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

    public function deploymentDispatches(Request $request, Deployment $deployment): JsonResponse
    {
        $this->access->assertCanViewDeployment($request->user(), $deployment);
        $query = $deployment->dispatchRequests()
            ->with([
                'targetTeam',
                'deployment.deploymentRequest.workflowRevision',
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
    private function dispatchPayloadForActor(DispatchRequest $dispatch, User $actor): array
    {
        $payload = MobileApiPayload::dispatch($dispatch, $actor, $this->deploymentRequestService);
        if (! $actor->isOperatorClient() || $dispatch->status !== 'draft' || $dispatch->deployment === null) {
            return $payload;
        }

        $place = $this->service->placeNameFromLocation($dispatch->deployment->location_label);
        $payload['deployment'] = [
            'id' => $dispatch->deployment->id,
            'reference' => 'Vooraankondiging',
            'title' => $place === null ? 'Beschikbaar voor een mogelijke inzet?' : "Beschikbaar voor een mogelijke inzet in {$place}?",
            'description' => null,
            'priority' => 'normal',
            'status' => $dispatch->deployment->status,
            'is_test' => (bool) $dispatch->deployment->is_test,
            'location_label' => $place,
            'latitude' => null,
            'longitude' => null,
            'custom_fields' => (object) [],
            'deployment_request' => null,
        ];

        return $payload;
    }
}
