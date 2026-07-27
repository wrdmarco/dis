<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Deployment;
use App\Services\DeploymentAccessService;
use App\Services\DeploymentReportService;
use App\Services\DispatchStatisticsService;
use App\Support\MobileApiPayload;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ReportingController extends Controller
{
    public function deploymentPdf(Request $request, string $deploymentId, DeploymentAccessService $access): Response
    {
        $deployment = Deployment::query()->find($deploymentId);
        if ($deployment === null) {
            return ApiResponse::error('deployment_not_found', 'Inzet niet gevonden.', 404);
        }

        $access->assertCanViewDeployment($request->user(), $deployment);

        if (! in_array($deployment->status, ['resolved', 'cancelled'], true)) {
            return ApiResponse::error('deployment_not_closed', 'Een rapport kan pas worden gemaakt als de inzet is afgerond of geannuleerd.', 422);
        }

        try {
            $reports = app(DeploymentReportService::class);
            $reports->ensureStored($deployment);
            $pdfPath = $reports->storedPdfPath($deployment->refresh());

            if ($pdfPath === null) {
                return ApiResponse::error('deployment_report_unavailable', 'Het opgeslagen inzetrapport is nog niet beschikbaar.', 503);
            }

            return response()->download($pdfPath, $reports->filename($deployment), [
                'Content-Type' => 'application/pdf',
            ]);
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
                // Logging must not hide the actual report download failure.
            }

            return ApiResponse::error('deployment_report_failed', 'Inzetrapport kon niet worden opgehaald.', 500);
        }
    }

    public function dispatchStatistics(Request $request, DispatchStatisticsService $statistics): JsonResponse
    {
        $data = $request->validate([
            'deployment_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return ApiResponse::success($statistics->overview((int) ($data['deployment_limit'] ?? 5)));
    }

    public function deployments(
        Request $request,
        DeploymentReportService $reports,
        DeploymentAccessService $access,
    ): JsonResponse {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Deployment::query()
            ->with([
                'team',
                'coordinator',
                'pilotReports.user' => fn ($query) => $query->withTrashed(),
                'dispatchRequests.recipients.user' => fn ($query) => $query->withTrashed(),
            ]);
        $access->scopeDeployments($query, $request->user());

        $deployments = $query
            ->where('is_test', false)
            ->whereIn('status', ['resolved', 'cancelled'])
            ->orderByDesc('closed_at')
            ->orderByDesc('created_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get()
            ->map(function (Deployment $deployment): array {
                $attendanceDispatches = $deployment->dispatchRequests
                    ->whereIn('status', ['sent', 'escalated']);
                $recipients = $attendanceDispatches->flatMap->recipients;
                $acceptedRecipients = $recipients
                    ->where('response_status', 'accepted')
                    ->filter(fn ($recipient): bool => is_string($recipient->user_id) && $recipient->user_id !== '')
                    ->unique('user_id')
                    ->values();
                $submittedReports = $deployment->pilotReports
                    ->where('status', 'submitted')
                    ->filter(fn ($report): bool => is_string($report->user_id) && $report->user_id !== '')
                    ->keyBy('user_id');
                $acceptedUserIds = $acceptedRecipients->pluck('user_id');
                $missingReports = $acceptedRecipients
                    ->filter(fn ($recipient): bool => ! $submittedReports->has($recipient->user_id))
                    ->map(fn ($recipient): array => [
                        'user_id' => $recipient->user_id,
                        'name' => $recipient->user?->name ?? $recipient->user_name ?? 'Onbekende gebruiker',
                        'email' => $recipient->user?->email ?? $recipient->user_email,
                        'responded_at' => MobileApiPayload::dateTime($recipient->responded_at),
                    ])
                    ->values();
                $unfinalizedReports = $submittedReports
                    ->filter(fn ($report, mixed $userId): bool => $acceptedUserIds->contains($userId) && $report->finalized_at === null)
                    ->map(fn ($report): array => [
                        'user_id' => $report->user_id,
                        'name' => $report->user?->name ?? $report->user_name ?? 'Onbekende gebruiker',
                        'email' => $report->user?->email ?? $report->user_email,
                        'submitted_at' => MobileApiPayload::dateTime($report->submitted_at),
                    ])
                    ->values();
                $latestDispatchAt = $deployment->dispatchRequests
                    ->map(fn ($dispatch): ?DateTimeInterface => $dispatch->sent_at ?? $dispatch->created_at)
                    ->filter()
                    ->sortDesc()
                    ->first();
                $expectedReportCount = $acceptedRecipients->count();
                $submittedReportCount = $submittedReports->count();
                $missingReportCount = $missingReports->count();
                $unfinalizedReportCount = $unfinalizedReports->count();
                $reportStatus = $missingReportCount === 0 && $unfinalizedReportCount === 0 ? 'final' : 'draft';

                return [
                    'id' => $deployment->id,
                    'reference' => $deployment->reference,
                    'title' => $deployment->title,
                    'status' => $deployment->status,
                    'priority' => $deployment->priority,
                    'team' => $deployment->team === null ? null : [
                        'id' => $deployment->team->id,
                        'code' => $deployment->team->code,
                        'name' => $deployment->team->name,
                    ],
                    'coordinator' => $deployment->coordinator === null && $deployment->coordinator_name === null ? null : [
                        'id' => $deployment->coordinator?->id ?? $deployment->coordinator_id,
                        'name' => $deployment->coordinator?->name ?? $deployment->coordinator_name,
                        'email' => $deployment->coordinator?->email ?? $deployment->coordinator_email,
                    ],
                    'opened_at' => MobileApiPayload::dateTime($deployment->opened_at),
                    'closed_at' => MobileApiPayload::dateTime($deployment->closed_at),
                    'report_generated_at' => MobileApiPayload::dateTime($deployment->report_generated_at),
                    'report_available' => is_string($deployment->report_pdf_path) && $deployment->report_pdf_path !== '',
                    'report_status' => $reportStatus,
                    'latest_dispatch_sent_at' => MobileApiPayload::dateTime($latestDispatchAt),
                    'recipient_count' => $recipients->count(),
                    'accepted' => $recipients->where('response_status', 'accepted')->count(),
                    'declined' => $recipients->where('response_status', 'declined')->count(),
                    'no_response' => $recipients->whereIn('response_status', ['pending', 'no_response'])->count(),
                    'expected_pilot_report_count' => $expectedReportCount,
                    'submitted_pilot_report_count' => $submittedReportCount,
                    'missing_pilot_report_count' => $missingReportCount,
                    'unfinalized_pilot_report_count' => $unfinalizedReportCount,
                    'missing_pilot_reports' => $missingReports,
                    'unfinalized_pilot_reports' => $unfinalizedReports,
                ];
            })
            ->values();

        return ApiResponse::success($deployments);
    }
}
