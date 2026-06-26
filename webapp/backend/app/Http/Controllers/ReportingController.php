<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Services\DispatchStatisticsService;
use App\Services\IncidentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ReportingController extends Controller
{
    public function incidentPdf(Incident $incident, IncidentReportService $reports): Response
    {
        if (! in_array($incident->status, ['resolved', 'cancelled'], true)) {
            return ApiResponse::error('incident_not_closed', 'Een rapport kan pas worden gemaakt als het incident is afgerond of geannuleerd.', 422);
        }

        $filename = Str::slug($incident->reference.'-'.$incident->title).'.pdf';

        return response($reports->pdf($incident), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function dispatchStatistics(Request $request, DispatchStatisticsService $statistics): JsonResponse
    {
        $data = $request->validate([
            'incident_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return ApiResponse::success($statistics->overview((int) ($data['incident_limit'] ?? 5)));
    }
}
