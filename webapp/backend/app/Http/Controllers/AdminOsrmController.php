<?php

namespace App\Http\Controllers;

use App\Exceptions\OsrmOperationConflictException;
use App\Exceptions\OsrmRequestPublicationException;
use App\Http\Requests\Admin\StartOsrmOperationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\OsrmOperation;
use App\Services\AuditService;
use App\Services\OsrmOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminOsrmController extends Controller
{
    public function __construct(
        private readonly OsrmOperationService $operations,
        private readonly AuditService $auditService,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->operations->managementStatus());
    }

    public function store(StartOsrmOperationRequest $request): JsonResponse
    {
        /** @var array{action: string, health_coordinate?: array{longitude: float|int|string, latitude: float|int|string}} $data */
        $data = $request->validated();
        $actor = $request->user();
        abort_if($actor === null, 401);

        try {
            $operation = $this->operations->start(
                action: $data['action'],
                healthCoordinate: $data['health_coordinate'] ?? null,
                actor: $actor,
                request: $request,
            );
        } catch (OsrmOperationConflictException $exception) {
            return ApiResponse::error(
                'osrm_operation_conflict',
                $exception->getMessage(),
                409,
            );
        } catch (OsrmRequestPublicationException $exception) {
            $this->recordStartFailure($request, $exception->operation, 'request_publication_failed');

            return ApiResponse::error(
                'osrm_request_unavailable',
                'De beveiligde OSRM request service is niet beschikbaar.',
                503,
                ['operation_id' => (string) $exception->operation->getKey()],
            );
        }

        return ApiResponse::success([
            'operation' => $this->operations->summary($operation),
        ], 202);
    }

    public function operation(Request $request, OsrmOperation $operation): JsonResponse
    {
        try {
            $data = $request->validate([
                'after' => ['nullable', 'integer', 'min:0'],
                'limit' => ['nullable', 'integer', 'between:1,200'],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return ApiResponse::success($this->operations->feed(
            operation: $operation,
            after: (int) ($data['after'] ?? 0),
            limit: (int) ($data['limit'] ?? 200),
        ));
    }

    private function recordStartFailure(Request $request, OsrmOperation $operation, string $reason): void
    {
        try {
            $this->auditService->record(
                action: 'routing.osrm.operation_not_started',
                target: $operation,
                actor: $request->user(),
                metadata: [
                    'operation_action' => $operation->action,
                    'reason' => $reason,
                ],
                request: $request,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
