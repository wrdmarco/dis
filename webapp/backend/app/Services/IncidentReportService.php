<?php

namespace App\Services;

use App\Models\AvailabilityStatus;
use App\Models\DispatchRequest;
use App\Models\Incident;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class IncidentReportService
{
    public function __construct(private readonly DroneFlightContextService $droneFlightContextService) {}

    /**
     * @return array<string, mixed>
     */
    public function data(Incident $incident): array
    {
        $incident->loadMissing(['creator', 'coordinator', 'team']);
        $dispatches = $incident->dispatchRequests()
            ->with([
                'targetTeam',
                'recipients.user' => fn ($query) => $query->withTrashed(),
                'messages.sender' => fn ($query) => $query->withTrashed(),
            ])
            ->oldest()
            ->get();

        $travelRows = $this->travelRows($dispatches);
        $timeline = $this->timeline($incident, $dispatches);
        $droneFlightContext = $incident->drone_flight_context ?? $this->droneFlightContextService->previewForIncident($incident);

        return [
            'incident' => $incident,
            'dispatches' => $dispatches,
            'travelRows' => $travelRows,
            'timeline' => $timeline,
            'map' => $this->mapData($incident, is_array($droneFlightContext) ? $droneFlightContext : null),
            'droneFlightContext' => $droneFlightContext,
            'summary' => [
                'recipients' => $travelRows->count(),
                'accepted' => $travelRows->where('response_status', 'accepted')->count(),
                'declined' => $travelRows->where('response_status', 'declined')->count(),
                'no_response' => $travelRows->whereIn('response_status', ['pending', 'no_response'])->count(),
                'en_route' => $travelRows->whereNotNull('en_route_at')->count(),
                'on_scene' => $travelRows->whereNotNull('on_scene_at')->count(),
            ],
            'generatedAt' => now(),
            'timezone' => config('app.timezone', 'Europe/Amsterdam'),
        ];
    }

    public function pdf(Incident $incident): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('reports.incident', $this->data($incident))->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param Collection<int, DispatchRequest> $dispatches
     * @return Collection<int, array<string, mixed>>
     */
    private function travelRows(Collection $dispatches): Collection
    {
        $userIds = $dispatches
            ->flatMap(fn (DispatchRequest $dispatch) => $dispatch->recipients->pluck('user_id'))
            ->unique()
            ->values();

        $firstNotifiedAt = $dispatches
            ->flatMap(fn (DispatchRequest $dispatch) => $dispatch->recipients->map(fn ($recipient): array => [
                'user_id' => $recipient->user_id,
                'started_at' => $recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at,
            ]))
            ->filter(fn (array $row): bool => $row['started_at'] !== null)
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows->pluck('started_at')->min());

        $statusesByUser = $userIds->isEmpty()
            ? collect()
            : AvailabilityStatus::query()
                ->with(['user' => fn ($query) => $query->withTrashed()])
                ->whereIn('user_id', $userIds)
                ->whereIn('status', ['en_route', 'on_scene'])
                ->oldest('effective_at')
                ->get()
                ->groupBy('user_id');

        return $dispatches
            ->flatMap(fn (DispatchRequest $dispatch) => $dispatch->recipients->map(function ($recipient) use ($dispatch, $firstNotifiedAt, $statusesByUser): array {
                $startedAt = $firstNotifiedAt->get($recipient->user_id) ?? $recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at;
                $userStatuses = $statusesByUser->get($recipient->user_id, collect())
                    ->filter(fn (AvailabilityStatus $status): bool => $startedAt === null || $status->effective_at?->greaterThanOrEqualTo($startedAt) === true);
                $enRoute = $userStatuses->firstWhere('status', 'en_route');
                $onScene = $userStatuses->firstWhere('status', 'on_scene');

                return [
                    'dispatch' => $dispatch,
                    'user' => $recipient->user,
                    'response_status' => $recipient->response_status,
                    'response_note' => $recipient->response_note,
                    'notified_at' => $recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at,
                    'responded_at' => $recipient->responded_at,
                    'en_route_at' => $enRoute?->effective_at,
                    'on_scene_at' => $onScene?->effective_at,
                    'response_minutes' => $this->minutesBetween($recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at, $recipient->responded_at),
                    'drive_minutes' => $this->minutesBetween($enRoute?->effective_at, $onScene?->effective_at),
                    'total_minutes' => $this->minutesBetween($recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at, $onScene?->effective_at),
                ];
            }))
            ->sortBy(fn (array $row) => strtolower((string) ($row['user']?->name ?? '')))
            ->values();
    }

    /**
     * @param Collection<int, DispatchRequest> $dispatches
     * @return Collection<int, array{id: string, type: string, label: string, message: string|null, created_at: mixed}>
     */
    private function timeline(Incident $incident, Collection $dispatches): Collection
    {
        $statusItems = $incident->statusHistory()
            ->oldest('created_at')
            ->get()
            ->map(fn ($item): array => [
                'id' => $item->id,
                'type' => 'Incidentstatus',
                'label' => trim(($item->from_status ?? 'nieuw').' -> '.$item->to_status),
                'message' => $item->reason,
                'created_at' => $item->created_at,
            ]);

        $dispatchItems = $dispatches->flatMap(function (DispatchRequest $dispatch): array {
            $items = [[
                'id' => $dispatch->id,
                'type' => 'Alarmering',
                'label' => 'Dispatch '.$dispatch->status,
                'message' => $dispatch->message,
                'created_at' => $dispatch->created_at,
            ]];

            foreach ($dispatch->recipients as $recipient) {
                $items[] = [
                    'id' => $recipient->id,
                    'type' => 'Opkomst',
                    'label' => ($recipient->user?->name ?? 'Onbekende gebruiker').' - '.$this->responseLabel($recipient->response_status),
                    'message' => $recipient->response_note,
                    'created_at' => $recipient->responded_at ?? $recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at,
                ];
            }

            foreach ($dispatch->messages as $message) {
                $items[] = [
                    'id' => $message->id,
                    'type' => 'Nadere info',
                    'label' => 'Nadere info'.($message->sender?->name ? ' - '.$message->sender->name : ''),
                    'message' => $message->body,
                    'created_at' => $message->created_at,
                ];
            }

            return $items;
        });

        $recipientStartsByUser = $dispatches
            ->flatMap(fn (DispatchRequest $dispatch) => $dispatch->recipients->map(fn ($recipient): array => [
                'user_id' => $recipient->user_id,
                'started_at' => $recipient->responded_at ?? $recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at,
            ]))
            ->filter(fn (array $recipient): bool => $recipient['started_at'] !== null)
            ->groupBy('user_id')
            ->map(fn (Collection $recipients) => $recipients->pluck('started_at')->min());

        $operatorStatusItems = collect();
        if ($recipientStartsByUser->isNotEmpty()) {
            $operatorStatusItems = AvailabilityStatus::query()
                ->with(['user' => fn ($query) => $query->withTrashed()])
                ->whereIn('user_id', $recipientStartsByUser->keys())
                ->whereIn('status', ['en_route', 'on_scene'])
                ->oldest('effective_at')
                ->get()
                ->filter(fn (AvailabilityStatus $status): bool => $status->effective_at?->greaterThanOrEqualTo($recipientStartsByUser->get($status->user_id)) === true)
                ->map(fn (AvailabilityStatus $status): array => [
                    'id' => $status->id,
                    'type' => 'Operationele status',
                    'label' => ($status->user?->name ?? 'Onbekende gebruiker').' - '.$this->operatorStatusLabel($status->status),
                    'message' => $status->reason,
                    'created_at' => $status->effective_at,
                ]);
        }

        return $statusItems
            ->concat($dispatchItems)
            ->concat($operatorStatusItems)
            ->filter(fn (array $item): bool => $item['created_at'] !== null)
            ->sortBy('created_at')
            ->values();
    }

    private function minutesBetween(mixed $start, mixed $end): ?int
    {
        if ($start === null || $end === null) {
            return null;
        }

        return max(0, (int) round($start->diffInSeconds($end) / 60));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapData(Incident $incident, ?array $droneFlightContext): array
    {
        $latitude = $this->coordinate($incident->latitude);
        $longitude = $this->coordinate($incident->longitude);

        if ($latitude === null || $longitude === null) {
            return [
                'available' => false,
                'latitude' => null,
                'longitude' => null,
                'latitude_label' => null,
                'longitude_label' => null,
                'marker_x' => 50.0,
                'marker_y' => 50.0,
                'aeret_url' => null,
                'openstreetmap_url' => null,
            ];
        }

        $flightMap = is_array($droneFlightContext['map'] ?? null) ? $droneFlightContext['map'] : [];

        return [
            'available' => true,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'latitude_label' => number_format($latitude, 6, '.', ''),
            'longitude_label' => number_format($longitude, 6, '.', ''),
            'marker_x' => $this->markerPercentage($longitude, 3.2, 7.3),
            'marker_y' => $this->markerPercentage($latitude, 50.7, 53.7, true),
            'aeret_url' => is_string($flightMap['aeret_url'] ?? null) ? $flightMap['aeret_url'] : null,
            'openstreetmap_url' => sprintf(
                'https://www.openstreetmap.org/?mlat=%1$.6f&mlon=%2$.6f#map=16/%1$.6f/%2$.6f',
                $latitude,
                $longitude,
            ),
        ];
    }

    private function coordinate(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) ? $coordinate : null;
    }

    private function markerPercentage(float $value, float $min, float $max, bool $invert = false): float
    {
        $percentage = (($value - $min) / ($max - $min)) * 100;

        if ($invert) {
            $percentage = 100 - $percentage;
        }

        return round(min(88, max(12, $percentage)), 1);
    }

    private function responseLabel(string $status): string
    {
        return match ($status) {
            'accepted' => 'komt',
            'declined' => 'komt niet',
            'no_response' => 'geen reactie',
            default => 'wacht op reactie',
        };
    }

    private function operatorStatusLabel(string $status): string
    {
        return match ($status) {
            'en_route' => 'Onderweg',
            'on_scene' => 'Op locatie',
            default => Str::headline($status),
        };
    }
}
