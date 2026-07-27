<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\DeploymentFormService;
use App\Services\DeploymentRequestWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DeploymentFormController extends Controller
{
    public function __construct(
        private readonly DeploymentFormService $formService,
        private readonly DeploymentRequestWorkflowService $deploymentRequestWorkflowService,
        private readonly AuditService $auditService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $target = (string) $request->query('target', $request->is('api/admin/*') ? 'web' : 'operator');

        return ApiResponse::success([
            'fields' => $this->formService->fields(target: $target),
            'layout' => $target === 'operator' ? [] : $this->formService->layout(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array'],
            'layout' => ['nullable', 'array'],
        ]);

        $fields = $this->formService->validateFields($data['fields']);
        $layout = $this->formService->validateLayout($data['layout'] ?? []);

        DB::transaction(function () use ($fields, $layout, $request): void {
            $this->deploymentRequestWorkflowService->acquireFormContractMutationLock();
            SystemSetting::query()->updateOrCreate(
                ['key' => DeploymentFormService::SETTING_KEY],
                ['value' => $fields, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
            );
            SystemSetting::query()->updateOrCreate(
                ['key' => DeploymentFormService::LAYOUT_SETTING_KEY],
                ['value' => $layout, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
            );

            // The current deployment form and published deployment-request workflow form one
            // contract. Reject an deployment-form mutation that would invalidate
            // published bindings; historical deployment-request revisions remain frozen.
            $published = $this->deploymentRequestWorkflowService->validatePublishedFormContract();
            $this->auditService->record('deployment_form.updated', 'deployment-form', $request->user(), [
                'field_count' => count($fields),
                'layout_item_count' => count($layout),
                'deployment_request_workflow_version' => $published->version,
            ]);
        });

        return $this->show($request);
    }
}
