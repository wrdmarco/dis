<?php

namespace App\Http\Controllers;

use App\Http\Requests\Deployments\DeleteDeploymentPilotAssignmentRequest;
use App\Http\Requests\Deployments\ListDeploymentPilotCandidatesRequest;
use App\Http\Requests\Deployments\ListDeploymentPilotsRequest;
use App\Http\Requests\Deployments\StoreDeploymentPilotAssignmentRequest;
use App\Http\Requests\Deployments\UpdateDeploymentPilotStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Deployment;
use App\Models\DeploymentPilotAssignment;
use App\Models\User;
use App\Services\DeploymentPilotAssignmentService;
use App\Support\ApiDateTime;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class DeploymentPilotController extends Controller
{
    public function __construct(
        private readonly DeploymentPilotAssignmentService $service,
    ) {}

    public function index(ListDeploymentPilotsRequest $request, Deployment $deployment): JsonResponse
    {
        return ApiResponse::success(
            $this->service->participants($deployment, $request->user()),
        );
    }

    public function candidates(
        ListDeploymentPilotCandidatesRequest $request,
        Deployment $deployment,
    ): JsonResponse {
        $data = $request->validated();

        return ApiResponse::paginated($this->service->candidates(
            $deployment,
            $request->user(),
            isset($data['search']) ? (string) $data['search'] : null,
            (int) ($data['per_page'] ?? 50),
            (int) ($data['page'] ?? 1),
        ));
    }

    public function store(
        StoreDeploymentPilotAssignmentRequest $request,
        Deployment $deployment,
    ): JsonResponse {
        $result = $this->service->assign(
            $deployment,
            (string) $request->validated('user_id'),
            (string) $request->validated('reason'),
            $request->user(),
            $request,
        );
        $notificationQueuedTokens = $result['queued_tokens'];

        return ApiResponse::success(
            [
                ...$result['participant'],
                'notification_queued_tokens' => $notificationQueuedTokens,
            ],
            201,
            [
                'notification_queued_tokens' => $notificationQueuedTokens,
                'warnings' => $notificationQueuedTokens === 0
                    ? ['De piloot is gekoppeld, maar de informatieve melding kon niet worden ingepland.']
                    : [],
            ],
        );
    }

    public function destroy(
        DeleteDeploymentPilotAssignmentRequest $request,
        Deployment $deployment,
        DeploymentPilotAssignment $assignment,
    ): Response {
        $this->service->remove($deployment, $assignment, $request->user(), $request);

        return response()->noContent();
    }

    public function updateStatus(
        UpdateDeploymentPilotStatusRequest $request,
        Deployment $deployment,
        User $pilot,
    ): JsonResponse {
        $status = $this->service->updateStatus(
            $deployment,
            $pilot,
            (string) $request->validated('status'),
            (string) $request->validated('reason'),
            $request->user(),
        );

        return ApiResponse::success([
            'id' => (string) $status->id,
            'user_id' => (string) $status->user_id,
            'status' => (string) $status->status,
            'is_available' => (bool) $status->is_available,
            'is_system_applied' => (bool) $status->is_system_applied,
            'reason' => $status->reason,
            'effective_at' => ApiDateTime::dateTime($status->effective_at),
        ]);
    }
}
