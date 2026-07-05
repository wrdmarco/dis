<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\UpdatePilotIncidentReportRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Services\PilotIncidentReportService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PilotIncidentReportController extends Controller
{
    public function __construct(private readonly PilotIncidentReportService $service) {}

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
