<?php

namespace App\Http\Controllers;

use App\Exceptions\DeploymentRequestConflictException;
use App\Http\Requests\DeploymentRequests\DecideDeploymentRequestPriorityRequest;
use App\Http\Requests\DeploymentRequests\DeploymentRequestMutationRequest;
use App\Http\Requests\DeploymentRequests\PatchDeploymentRequest;
use App\Http\Requests\DeploymentRequests\StoreDeploymentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Deployment;
use App\Models\DeploymentRequest;
use App\Services\DeploymentRequestService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeploymentRequestController extends Controller
{
    public function __construct(private readonly DeploymentRequestService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:80', 'regex:/^(open|prepared|closed)(,(open|prepared|closed))*$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $statuses = collect(explode(',', (string) ($data['status'] ?? 'open,prepared')))
            ->map(fn (string $status): string => trim($status))
            ->unique()
            ->values()
            ->all();
        $paginator = $this->service->search($statuses, (int) ($data['per_page'] ?? 25));

        return ApiResponse::paginated($paginator, fn (DeploymentRequest $deploymentRequest): array => $this->service->present($deploymentRequest, $request->user()));
    }

    public function store(StoreDeploymentRequest $request): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->create($request->validated(), $request->user()), 201);
        } catch (DeploymentRequestConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function show(Request $request, DeploymentRequest $deploymentRequest): JsonResponse
    {
        return ApiResponse::success($this->service->present($deploymentRequest, $request->user()));
    }

    public function update(PatchDeploymentRequest $request, DeploymentRequest $deploymentRequest): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->patch($deploymentRequest, $request->validated(), $request->user()));
        } catch (DeploymentRequestConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function decide(DecideDeploymentRequestPriorityRequest $request, DeploymentRequest $deploymentRequest): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->decidePriority($deploymentRequest, $request->validated(), $request->user()));
        } catch (DeploymentRequestConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function prepareDeployment(DeploymentRequestMutationRequest $request, DeploymentRequest $deploymentRequest): JsonResponse
    {
        try {
            $result = $this->service->prepareDeployment($deploymentRequest, $request->validated(), $request->user());
        } catch (DeploymentRequestConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success([
            'deployment_request' => $result['deployment_request'],
            'deployment' => MobileApiPayload::deployment($result['deployment'], $request->user()),
        ], 201);
    }

    public function close(DeploymentRequestMutationRequest $request, DeploymentRequest $deploymentRequest): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->close($deploymentRequest, $request->validated(), $request->user()));
        } catch (DeploymentRequestConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function showForDeployment(Request $request, Deployment $deployment): JsonResponse
    {
        $deploymentRequest = $deployment->deploymentRequest()->firstOrFail();

        return ApiResponse::success($this->service->present($deploymentRequest, $request->user()));
    }

    public function updateForDeployment(PatchDeploymentRequest $request, Deployment $deployment): JsonResponse
    {
        $deploymentRequest = $deployment->deploymentRequest()->firstOrFail();
        try {
            return ApiResponse::success($this->service->patch($deploymentRequest, $request->validated(), $request->user()));
        } catch (DeploymentRequestConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    private function conflict(DeploymentRequestConflictException $exception): JsonResponse
    {
        return ApiResponse::error($exception->errorCode, $exception->getMessage(), 409, ['current' => $exception->current]);
    }
}
