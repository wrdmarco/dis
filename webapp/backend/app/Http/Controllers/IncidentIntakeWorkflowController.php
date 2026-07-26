<?php

namespace App\Http\Controllers;

use App\Exceptions\IncidentIntakeConflictException;
use App\Http\Requests\IncidentIntakes\UpdateIntakeWorkflowDraftRequest;
use App\Http\Responses\ApiResponse;
use App\Models\IncidentIntakeDossier;
use App\Services\AuditService;
use App\Services\IncidentIntakeWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class IncidentIntakeWorkflowController extends Controller
{
    public function __construct(
        private readonly IncidentIntakeWorkflowService $service,
        private readonly AuditService $auditService,
    ) {}

    public function published(Request $request): JsonResponse
    {
        $dossierId = $request->query('dossier_id');
        $revision = is_string($dossierId) && $dossierId !== ''
            ? IncidentIntakeDossier::query()->with('workflowRevision')->findOrFail($dossierId)->workflowRevision
            : $this->service->published();

        return ApiResponse::success($this->service->revisionPayload($revision));
    }

    public function adminConfig(): JsonResponse
    {
        return ApiResponse::success($this->service->adminEnvelope());
    }

    public function updateDraft(UpdateIntakeWorkflowDraftRequest $request): JsonResponse
    {
        try {
            $payload = DB::transaction(function () use ($request): array {
                $payload = $this->service->updateDraft(
                    (int) $request->validated('expected_revision'),
                    $request->validated('configuration'),
                    $request->user(),
                );
                $this->auditService->record('intake_workflow.draft_updated', 'incident-intake-workflow', $request->user(), [
                    'lock_version' => $payload['draft']['lock_version'],
                ]);

                return $payload;
            });
        } catch (IncidentIntakeConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success($payload);
    }

    public function validateDraft(UpdateIntakeWorkflowDraftRequest $request): JsonResponse
    {
        $envelope = $this->service->adminEnvelope();
        if ((int) $request->validated('expected_revision') !== (int) $envelope['draft']['lock_version']) {
            return ApiResponse::error(
                'intake_workflow_conflict',
                'De formulierconfiguratie is intussen gewijzigd.',
                409,
                ['current' => $envelope['draft']],
            );
        }

        return ApiResponse::success([
            'valid' => true,
            'configuration' => $this->service->validateConfiguration($request->validated('configuration')),
        ]);
    }

    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => ['nullable', 'integer', 'min:1'],
            'subject_type' => ['required', 'string', 'in:person,animal,object'],
            'answers' => ['required', 'array', 'max:100'],
        ]);
        $envelope = $this->service->adminEnvelope();
        if (isset($data['expected_revision']) && (int) $data['expected_revision'] !== (int) $envelope['draft']['lock_version']) {
            return ApiResponse::error(
                'intake_workflow_conflict',
                'De formulierconfiguratie is intussen gewijzigd.',
                409,
                ['current' => $envelope['draft']],
            );
        }
        $configuration = $envelope['draft']['configuration'];
        $answers = $this->service->normalizeAnswers($configuration, $data['subject_type'], $data['answers']);

        return ApiResponse::success($this->service->evaluate($configuration, $data['subject_type'], $answers));
    }

    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        try {
            $payload = DB::transaction(function () use ($data, $request): array {
                $payload = $this->service->publishDraft((int) $data['expected_revision'], $request->user());
                $this->auditService->record('intake_workflow.published', 'incident-intake-workflow', $request->user(), [
                    'version' => $payload['published']['version'],
                ]);

                return $payload;
            });
        } catch (IncidentIntakeConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success($payload);
    }

    public function restore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'published_revision_id' => ['required', 'ulid', 'exists:incident_intake_workflow_revisions,id'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);
        try {
            $payload = DB::transaction(function () use ($data, $request): array {
                $payload = $this->service->restore($data['published_revision_id'], (int) $data['expected_revision'], $request->user());
                $this->auditService->record('intake_workflow.restored_to_draft', 'incident-intake-workflow', $request->user(), [
                    'source_revision_id' => $data['published_revision_id'],
                    'draft_lock_version' => $payload['draft']['lock_version'],
                ]);

                return $payload;
            });
        } catch (IncidentIntakeConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success($payload);
    }

    private function conflict(IncidentIntakeConflictException $exception): JsonResponse
    {
        return ApiResponse::error($exception->errorCode, $exception->getMessage(), 409, ['current' => $exception->current]);
    }
}
