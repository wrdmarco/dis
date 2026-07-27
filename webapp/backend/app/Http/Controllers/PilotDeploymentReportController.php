<?php

namespace App\Http\Controllers;

use App\Http\Requests\Deployments\UpdatePilotDeploymentReportRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Deployment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DeploymentAccessService;
use App\Services\PilotDeploymentReportFormService;
use App\Services\PilotDeploymentReportService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PilotDeploymentReportController extends Controller
{
    public function __construct(
        private readonly PilotDeploymentReportService $service,
        private readonly PilotDeploymentReportFormService $formService,
        private readonly DeploymentAccessService $deploymentAccessService,
    ) {}

    public function formConfig(Request $request): JsonResponse
    {
        $targetUser = $request->user();
        if ($request->filled('user_id') && $request->user()?->hasPermission('deployments.manage') === true) {
            $targetUser = User::query()->findOrFail((string) $request->query('user_id'));
        }

        $target = (string) $request->query('target', $request->is('api/admin/*') ? 'web' : 'operator');
        $deployment = null;
        if ($request->filled('deployment_id')) {
            $actor = $request->user();
            abort_unless($actor instanceof User, 401);
            $deployment = $this->deploymentAccessService
                ->scopeDeployments(
                    Deployment::query()->whereKey((string) $request->query('deployment_id')),
                    $actor,
                )
                ->firstOrFail();
        }

        return ApiResponse::success(['fields' => $this->formService->fields(
            $targetUser,
            operatorOnly: $target === 'operator',
            deployment: $deployment,
        )]);
    }

    public function updateFormConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array'],
        ]);

        $fields = $this->formService->validateFields($data['fields']);

        SystemSetting::query()->updateOrCreate(
            ['key' => PilotDeploymentReportFormService::SETTING_KEY],
            ['value' => $fields, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
        );

        return ApiResponse::success(['fields' => $this->formService->fields()]);
    }

    public function show(Request $request, Deployment $deployment): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotDeploymentReport(
            $this->service->show($deployment, $request->user()),
        ));
    }

    public function update(UpdatePilotDeploymentReportRequest $request, Deployment $deployment): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotDeploymentReport(
            $this->service->submit($deployment, $request->user(), $request->validated()),
        ));
    }

    public function finalize(Request $request, Deployment $deployment): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotDeploymentReport(
            $this->service->finalize($deployment, $request->user(), $request->user()),
        ));
    }

    public function showForUser(Request $request, Deployment $deployment, User $user): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotDeploymentReport(
            $this->service->showForActor($deployment, $user, $request->user()),
        ));
    }

    public function updateForUser(Request $request, Deployment $deployment, User $user): JsonResponse
    {
        $data = $request->validate($this->formService->validationRules($user, $deployment));

        return ApiResponse::success(MobileApiPayload::pilotDeploymentReport(
            $this->service->submitForActor($deployment, $user, $request->user(), $data),
        ));
    }

    public function finalizeForUser(Request $request, Deployment $deployment, User $user): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotDeploymentReport(
            $this->service->finalize($deployment, $user, $request->user()),
        ));
    }
}
