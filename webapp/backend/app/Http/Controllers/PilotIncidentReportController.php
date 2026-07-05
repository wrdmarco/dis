<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\UpdatePilotIncidentReportRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Incident;
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

    public function formConfig(): JsonResponse
    {
        return ApiResponse::success(['fields' => $this->formService->fields()]);
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
}
