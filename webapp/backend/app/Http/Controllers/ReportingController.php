<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Services\DispatchStatisticsService;
use App\Services\IncidentReportService;
use App\Support\MobileApiPayload;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ReportingController extends Controller
{
    public function incidentPdf(Incident $incident, IncidentReportService $reports): Response
    {
        if (! in_array($incident->status, ['resolved', 'cancelled'], true)) {
            return ApiResponse::error('incident_not_closed', 'Een rapport kan pas worden gemaakt als het incident is afgerond of geannuleerd.', 422);
        }

        try {
            $pdfPath = $reports->storedPdfPath($incident);
            if ($pdfPath === null) {
                $reports->ensureStored($incident);
                $pdfPath = $reports->storedPdfPath($incident->refresh());
            }

            if ($pdfPath === null) {
                $message = $incident->report_generation_error !== null && $incident->report_generation_error !== ''
                    ? 'Incidentrapport kon niet worden gemaakt: '.$incident->report_generation_error
                    : 'Het opgeslagen incidentrapport is nog niet beschikbaar.';

                return ApiResponse::error('incident_report_unavailable', $message, 503);
            }

            return response()->download($pdfPath, $reports->filename($incident), [
                'Content-Type' => 'application/pdf',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('incident_report_failed', 'Incidentrapport kon niet worden opgehaald: '.mb_substr($exception->getMessage(), 0, 500), 500, [
                'exception' => class_basename($exception),
            ]);
        }
    }

    public function dispatchStatistics(Request $request, DispatchStatisticsService $statistics): JsonResponse
    {
        $data = $request->validate([
            'incident_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return ApiResponse::success($statistics->overview((int) ($data['incident_limit'] ?? 5)));
    }

    public function incidents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $incidents = Incident::query()
            ->with(['team', 'coordinator', 'dispatchRequests.recipients'])
            ->where('is_test', false)
            ->whereIn('status', ['resolved', 'cancelled'])
            ->orderByDesc('closed_at')
            ->orderByDesc('created_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get()
            ->map(function (Incident $incident): array {
                $recipients = $incident->dispatchRequests->flatMap->recipients;
                $latestDispatchAt = $incident->dispatchRequests
                    ->map(fn ($dispatch): ?DateTimeInterface => $dispatch->sent_at ?? $dispatch->created_at)
                    ->filter()
                    ->sortDesc()
                    ->first();

                return [
                    'id' => $incident->id,
                    'reference' => $incident->reference,
                    'title' => $incident->title,
                    'status' => $incident->status,
                    'priority' => $incident->priority,
                    'team' => $incident->team === null ? null : [
                        'id' => $incident->team->id,
                        'code' => $incident->team->code,
                        'name' => $incident->team->name,
                    ],
                    'coordinator' => $incident->coordinator === null && $incident->coordinator_name === null ? null : [
                        'id' => $incident->coordinator?->id ?? $incident->coordinator_id,
                        'name' => $incident->coordinator?->name ?? $incident->coordinator_name,
                        'email' => $incident->coordinator?->email ?? $incident->coordinator_email,
                    ],
                    'opened_at' => MobileApiPayload::dateTime($incident->opened_at),
                    'closed_at' => MobileApiPayload::dateTime($incident->closed_at),
                    'report_generated_at' => MobileApiPayload::dateTime($incident->report_generated_at),
                    'report_available' => is_string($incident->report_pdf_path) && $incident->report_pdf_path !== '',
                    'latest_dispatch_sent_at' => MobileApiPayload::dateTime($latestDispatchAt),
                    'recipient_count' => $recipients->count(),
                    'accepted' => $recipients->where('response_status', 'accepted')->count(),
                    'declined' => $recipients->where('response_status', 'declined')->count(),
                    'no_response' => $recipients->whereIn('response_status', ['pending', 'no_response'])->count(),
                ];
            })
            ->values();

        return ApiResponse::success($incidents);
    }
}
