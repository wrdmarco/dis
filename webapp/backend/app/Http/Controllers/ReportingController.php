<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Services\DispatchStatisticsService;
use App\Services\IncidentReportService;
use DateTimeInterface;
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
                    'coordinator' => $incident->coordinator === null ? null : [
                        'id' => $incident->coordinator->id,
                        'name' => $incident->coordinator->name,
                        'email' => $incident->coordinator->email,
                    ],
                    'opened_at' => $incident->opened_at?->toIso8601String(),
                    'closed_at' => $incident->closed_at?->toIso8601String(),
                    'latest_dispatch_sent_at' => $latestDispatchAt?->toIso8601String(),
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
