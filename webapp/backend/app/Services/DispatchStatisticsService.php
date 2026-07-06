<?php

namespace App\Services;

use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Support\ApiDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class DispatchStatisticsService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(int $incidentLimit = 5): array
    {
        $incidentLimit = min(max($incidentLimit, 1), 50);
        $incidentIds = DispatchRequest::query()
            ->whereHas('incident', fn ($query) => $query
                ->where('is_test', false)
                ->whereIn('status', ['resolved', 'cancelled']))
            ->whereNotNull('sent_at')
            ->with('incident')
            ->latest('sent_at')
            ->get()
            ->pluck('incident_id')
            ->filter()
            ->unique()
            ->take($incidentLimit)
            ->values();

        $recipients = DispatchRecipient::query()
            ->with([
                'user' => fn ($query) => $query->withTrashed(),
                'dispatchRequest.incident',
            ])
            ->whereHas('dispatchRequest', fn ($query) => $query->whereIn('incident_id', $incidentIds))
            ->get();

        $total = $recipients->count();
        $accepted = $recipients->where('response_status', 'accepted')->count();
        $declined = $recipients->where('response_status', 'declined')->count();
        $noResponse = $recipients->whereIn('response_status', ['pending', 'no_response'])->count();

        return [
            'scope' => [
                'incident_limit' => $incidentLimit,
                'incident_count' => $incidentIds->count(),
            ],
            'summary' => [
                'total_alerts' => $total,
                'accepted' => $accepted,
                'declined' => $declined,
                'no_response' => $noResponse,
                'accepted_rate' => $this->percentage($accepted, $total),
                'declined_rate' => $this->percentage($declined, $total),
                'no_response_rate' => $this->percentage($noResponse, $total),
            ],
            'users' => $this->userStats($recipients),
            'incidents' => $this->incidentStats($recipients),
        ];
    }

    /**
     * @param Collection<int, DispatchRecipient> $recipients
     * @return array<int, array<string, mixed>>
     */
    private function userStats(Collection $recipients): array
    {
        return $recipients
            ->groupBy('user_id')
            ->map(function (Collection $rows): array {
                $total = $rows->count();
                $accepted = $rows->where('response_status', 'accepted')->count();
                $declined = $rows->where('response_status', 'declined')->count();
                $noResponseRows = $rows->whereIn('response_status', ['pending', 'no_response']);
                $firstRow = $rows->first();
                $user = $firstRow?->user;
                $sorted = $rows->sortByDesc(fn (DispatchRecipient $recipient) => $this->timestamp($recipient->dispatchRequest?->sent_at ?? $recipient->dispatchRequest?->created_at));
                $lastAlert = $sorted->first();
                $lastDeployment = $sorted->firstWhere('response_status', 'accepted');

                return [
                    'user' => [
                        'id' => $user?->id ?? $firstRow?->user_id,
                        'name' => $user?->name ?? $firstRow?->user_name ?? 'Verwijderde gebruiker',
                        'email' => $user?->email ?? $firstRow?->user_email,
                    ],
                    'total_alerts' => $total,
                    'accepted' => $accepted,
                    'declined' => $declined,
                    'no_response' => $noResponseRows->count(),
                    'no_response_rate' => $this->percentage($noResponseRows->count(), $total),
                    'last_alert' => $this->incidentSummary($lastAlert),
                    'last_deployment' => $this->incidentSummary($lastDeployment),
                    'recent_no_response' => $noResponseRows
                        ->sortByDesc(fn (DispatchRecipient $recipient) => $this->timestamp($recipient->dispatchRequest?->sent_at ?? $recipient->dispatchRequest?->created_at))
                        ->take(5)
                        ->map(fn (DispatchRecipient $recipient): ?array => $this->incidentSummary($recipient))
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc('no_response_rate')
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, DispatchRecipient> $recipients
     * @return array<int, array<string, mixed>>
     */
    private function incidentStats(Collection $recipients): array
    {
        return $recipients
            ->filter(fn (DispatchRecipient $recipient): bool => $recipient->dispatchRequest?->incident_id !== null)
            ->groupBy(fn (DispatchRecipient $recipient) => $recipient->dispatchRequest?->incident_id)
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $incident = $first?->dispatchRequest?->incident;
                $total = $rows->count();
                $noResponse = $rows->whereIn('response_status', ['pending', 'no_response'])->count();

                return [
                    'id' => $incident?->id,
                    'reference' => $incident?->reference,
                    'title' => $incident?->title,
                    'sent_at' => ApiDateTime::dateTime($first?->dispatchRequest?->sent_at),
                    'total_alerts' => $total,
                    'accepted' => $rows->where('response_status', 'accepted')->count(),
                    'declined' => $rows->where('response_status', 'declined')->count(),
                    'no_response' => $noResponse,
                    'no_response_rate' => $this->percentage($noResponse, $total),
                ];
            })
            ->sortByDesc('sent_at')
            ->values()
            ->all();
    }

    private function incidentSummary(?DispatchRecipient $recipient): ?array
    {
        $dispatch = $recipient?->dispatchRequest;
        $incident = $dispatch?->incident;

        if ($recipient === null || $dispatch === null || $incident === null) {
            return null;
        }

        return [
            'incident_id' => $incident->id,
            'reference' => $incident->reference,
            'title' => $incident->title,
            'sent_at' => ApiDateTime::dateTime($dispatch->sent_at),
            'response_status' => $recipient->response_status,
            'responded_at' => ApiDateTime::dateTime($recipient->responded_at),
        ];
    }

    private function percentage(int $part, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 1);
    }

    private function timestamp(mixed $value): int
    {
        if ($value instanceof Carbon) {
            return $value->getTimestamp();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return is_string($value) ? (strtotime($value) ?: 0) : 0;
    }
}
