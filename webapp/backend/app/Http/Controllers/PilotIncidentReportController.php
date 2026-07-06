<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\UpdatePilotIncidentReportRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Models\User;
use App\Services\PilotIncidentReportService;
use App\Services\PilotIncidentReportFormService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PilotIncidentReportController extends Controller
{
    public function __construct(
        private readonly PilotIncidentReportService $service,
        private readonly PilotIncidentReportFormService $formService,
    ) {}

    public function formConfig(Request $request): JsonResponse
    {
        $targetUser = $request->user();
        if ($request->filled('user_id') && $request->user()?->hasPermission('incidents.manage') === true) {
            $targetUser = User::query()->findOrFail((string) $request->query('user_id'));
        }

        $target = (string) $request->query('target', $request->is('api/admin/*') ? 'web' : 'operator');

        return ApiResponse::success(['fields' => $this->formService->fields($targetUser, operatorOnly: $target === 'operator')]);
    }

    public function updateFormConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array'],
        ]);

        $fields = $this->formService->validateFields($data['fields']);

        \App\Models\SystemSetting::query()->updateOrCreate(
            ['key' => PilotIncidentReportFormService::SETTING_KEY],
            ['value' => $fields, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
        );

        return ApiResponse::success(['fields' => $this->formService->fields()]);
    }

    public function show(Request $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotIncidentReport(
            $this->service->show($incident, $request->user()),
        ));
    }

    public function update(UpdatePilotIncidentReportRequest $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotIncidentReport(
            $this->service->submit($incident, $request->user(), $request->validated()),
        ));
    }

    public function finalize(Request $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotIncidentReport(
            $this->service->finalize($incident, $request->user(), $request->user()),
        ));
    }

    public function showForUser(Request $request, Incident $incident, User $user): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotIncidentReport(
            $this->service->showForActor($incident, $user, $request->user()),
        ));
    }

    public function updateForUser(Request $request, Incident $incident, User $user): JsonResponse
    {
        $data = $request->validate($this->formService->validationRules($user));

        return ApiResponse::success(MobileApiPayload::pilotIncidentReport(
            $this->service->submitForActor($incident, $user, $request->user(), $data),
        ));
    }

    public function finalizeForUser(Request $request, Incident $incident, User $user): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::pilotIncidentReport(
            $this->service->finalize($incident, $user, $request->user()),
        ));
    }
}
