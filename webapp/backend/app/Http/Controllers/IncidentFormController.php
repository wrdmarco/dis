<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\IncidentFormService;
use App\Services\IncidentIntakeWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class IncidentFormController extends Controller
{
    public function __construct(
        private readonly IncidentFormService $formService,
        private readonly IncidentIntakeWorkflowService $intakeWorkflowService,
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
            $this->intakeWorkflowService->acquireFormContractMutationLock();
            SystemSetting::query()->updateOrCreate(
                ['key' => IncidentFormService::SETTING_KEY],
                ['value' => $fields, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
            );
            SystemSetting::query()->updateOrCreate(
                ['key' => IncidentFormService::LAYOUT_SETTING_KEY],
                ['value' => $layout, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
            );

            // The current incident form and published intake workflow form one
            // contract. Reject an incident-form mutation that would invalidate
            // published bindings; historical dossier revisions remain frozen.
            $published = $this->intakeWorkflowService->validatePublishedFormContract();
            $this->auditService->record('incident_form.updated', 'incident-form', $request->user(), [
                'field_count' => count($fields),
                'layout_item_count' => count($layout),
                'intake_workflow_version' => $published->version,
            ]);
        });

        return $this->show($request);
    }
}
