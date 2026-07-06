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
    public function incidentPdf(string $incidentId): Response
    {
        try {
            $reports = app(IncidentReportService::class);
            $incident = Incident::query()->find($incidentId);
            if ($incident === null) {
                return ApiResponse::error('incident_not_found', 'Incident niet gevonden.', 404);
            }

            if (! in_array($incident->status, ['resolved', 'cancelled'], true)) {
                return ApiResponse::error('incident_not_closed', 'Een rapport kan pas worden gemaakt als het incident is afgerond of geannuleerd.', 422);
            }

            $pdfPath = $reports->storedPdfPath($incident);
            if ($pdfPath !== null) {
                $reports->refreshStored($incident, preserveExistingMaps: true);
                $pdfPath = $reports->storedPdfPath($incident->refresh());
            } else {
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
            try {
                report($exception);
            } catch (Throwable) {
                // Logging must not hide the actual report download failure.
            }

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
            ->with([
                'team',
                'coordinator',
                'pilotReports',
                'dispatchRequests.recipients.user' => fn ($query) => $query->withTrashed(),
            ])
            ->where('is_test', false)
            ->whereIn('status', ['resolved', 'cancelled'])
            ->orderByDesc('closed_at')
            ->orderByDesc('created_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get()
            ->map(function (Incident $incident): array {
                $attendanceDispatches = $incident->dispatchRequests
                    ->whereIn('status', ['sent', 'escalated']);
                $recipients = $attendanceDispatches->flatMap->recipients;
                $acceptedRecipients = $recipients
                    ->where('response_status', 'accepted')
                    ->filter(fn ($recipient): bool => is_string($recipient->user_id) && $recipient->user_id !== '')
                    ->unique('user_id')
                    ->values();
                $submittedReports = $incident->pilotReports
                    ->where('status', 'submitted')
                    ->filter(fn ($report): bool => is_string($report->user_id) && $report->user_id !== '')
                    ->keyBy('user_id');
                $missingReports = $acceptedRecipients
                    ->filter(fn ($recipient): bool => ! $submittedReports->has($recipient->user_id))
                    ->map(fn ($recipient): array => [
                        'user_id' => $recipient->user_id,
                        'name' => $recipient->user?->name ?? $recipient->user_name ?? 'Onbekende gebruiker',
                        'email' => $recipient->user?->email ?? $recipient->user_email,
                        'responded_at' => MobileApiPayload::dateTime($recipient->responded_at),
                    ])
                    ->values();
                $latestDispatchAt = $incident->dispatchRequests
                    ->map(fn ($dispatch): ?DateTimeInterface => $dispatch->sent_at ?? $dispatch->created_at)
                    ->filter()
                    ->sortDesc()
                    ->first();
                $expectedReportCount = $acceptedRecipients->count();
                $submittedReportCount = $submittedReports->count();
                $missingReportCount = $missingReports->count();

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
                    'report_status' => $missingReportCount === 0 ? 'final' : 'draft',
                    'latest_dispatch_sent_at' => MobileApiPayload::dateTime($latestDispatchAt),
                    'recipient_count' => $recipients->count(),
                    'accepted' => $recipients->where('response_status', 'accepted')->count(),
                    'declined' => $recipients->where('response_status', 'declined')->count(),
                    'no_response' => $recipients->whereIn('response_status', ['pending', 'no_response'])->count(),
                    'expected_pilot_report_count' => $expectedReportCount,
                    'submitted_pilot_report_count' => $submittedReportCount,
                    'missing_pilot_report_count' => $missingReportCount,
                    'missing_pilot_reports' => $missingReports,
                ];
            })
            ->values();

        return ApiResponse::success($incidents);
    }
}
