<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Deployment;
use App\Models\DispatchRequest;
use App\Services\AuditService;
use App\Services\DeveloperAccessService;
use App\Services\DeveloperDispatchDiagnosticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class DeveloperDispatchDiagnosticsController extends Controller
{
    public function __construct(
        private readonly DeveloperAccessService $developerAccess,
        private readonly DeveloperDispatchDiagnosticsService $diagnostics,
        private readonly AuditService $auditService,
    ) {}

    public function show(Request $request, string $dispatchId): JsonResponse
    {
        // Authenticate before validating or resolving the identifier, so this
        // public route never becomes an unauthenticated dispatch oracle.
        $this->developerAccess->authorize($request, DeveloperAccessService::SCOPE_LOGS_READ);
        Validator::make(
            ['dispatch_id' => $dispatchId],
            ['dispatch_id' => ['required', 'ulid']],
        )->validate();

        $dispatch = DispatchRequest::query()
            ->find($dispatchId, ['id', 'deployment_id', 'status', 'sent_at', 'cancelled_at', 'created_at', 'updated_at']);
        if ($dispatch === null) {
            return ApiResponse::error('dispatch_not_found', 'Dispatch niet gevonden.', 404);
        }

        $payload = $this->diagnostics->build($dispatch);
        $this->auditService->record(
            'developer.dispatch_diagnostics_read',
            $dispatch,
            null,
            [
                'outbox_count' => (int) data_get($payload, 'outbox.total', 0),
                'delivery_count' => (int) data_get($payload, 'deliveries.total', 0),
            ],
            null,
            $request,
        );

        return ApiResponse::success($payload);
    }

    public function indexForDeployment(Request $request, string $deploymentId): JsonResponse
    {
        // Keep authorization ahead of validation and lookup for the same
        // reason as the dispatch endpoint: no unauthenticated ID oracle.
        $this->developerAccess->authorize($request, DeveloperAccessService::SCOPE_LOGS_READ);
        Validator::make(
            ['deployment_id' => $deploymentId],
            ['deployment_id' => ['required', 'ulid']],
        )->validate();

        $deployment = Deployment::withTrashed()->find($deploymentId, ['id']);
        if ($deployment === null) {
            return ApiResponse::error('deployment_not_found', 'Inzet niet gevonden.', 404);
        }

        $payload = $this->diagnostics->listForDeployment($deployment);
        $this->auditService->record(
            'developer.deployment_dispatch_index_read',
            $deployment,
            null,
            ['dispatch_count' => (int) data_get($payload, 'total', 0)],
            null,
            $request,
        );

        return ApiResponse::success($payload);
    }
}
