<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\UpdatePilotIncidentReportRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IncidentAccessService;
use App\Services\PilotIncidentReportFormService;
use App\Services\PilotIncidentReportService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PilotIncidentReportController extends Controller
{
    public function __construct(
        private readonly PilotIncidentReportService $service,
        private readonly PilotIncidentReportFormService $formService,
        private readonly IncidentAccessService $incidentAccessService,
    ) {}

    public function formConfig(Request $request): JsonResponse
    {
        $targetUser = $request->user();
        if ($request->filled('user_id') && $request->user()?->hasPermission('incidents.manage') === true) {
            $targetUser = User::query()->findOrFail((string) $request->query('user_id'));
        }

        $target = (string) $request->query('target', $request->is('api/admin/*') ? 'web' : 'operator');
        $incident = null;
        if ($request->filled('incident_id')) {
            $actor = $request->user();
            abort_unless($actor instanceof User, 401);
            $incident = $this->incidentAccessService
                ->scopeIncidents(
                    Incident::query()->whereKey((string) $request->query('incident_id')),
                    $actor,
                )
                ->firstOrFail();
        }

        return ApiResponse::success(['fields' => $this->formService->fields(
            $targetUser,
            operatorOnly: $target === 'operator',
            incident: $incident,
        )]);
    }

    public function updateFormConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array'],
        ]);

        $fields = $this->formService->validateFields($data['fields']);

        SystemSetting::query()->updateOrCreate(
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
        $data = $request->validate($this->formService->validationRules($user, $incident));

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
