<?php

namespace App\Services;

use App\Models\AvailabilityStatus;
use App\Models\DispatchRequest;
use App\Models\Incident;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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
        Storage::disk('local')->makeDirectory('report-temp');
        Storage::disk('local')->makeDirectory('report-fonts');

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', storage_path('app/report-temp'));
        $options->set('fontDir', storage_path('app/report-fonts'));
        $options->set('fontCache', storage_path('app/report-fonts'));

        $data = $this->data($incident);
        try {
            return $this->renderPdf($options, $data);
        } catch (Throwable $exception) {
            report($exception);
        }

        $data['map']['snapshot_data_uri'] = null;
        $data['map']['snapshot_available'] = false;
        $data['map']['aeret_snapshot_data_uri'] = null;

        return $this->renderPdf($options, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPdf(Options $options, array $data): string
    {
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('reports.incident', $data)->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function ensureStored(Incident $incident): ?string
    {
        if (! in_array($incident->status, ['resolved', 'cancelled'], true)) {
            return null;
        }

        if (is_string($incident->report_pdf_path)
            && $incident->report_pdf_path !== ''
            && Storage::disk('local')->exists($incident->report_pdf_path)) {
            return $incident->report_pdf_path;
        }

        try {
            $path = $this->reportPath($incident);
            Storage::disk('local')->put($path, $this->pdf($incident));
            $incident->forceFill([
                'report_pdf_path' => $path,
                'report_generated_at' => now(),
                'report_generation_error' => null,
            ])->save();

            return $path;
        } catch (Throwable $exception) {
            report($exception);
            $incident->forceFill([
                'report_generation_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            return null;
        }
    }

    public function storedPdf(Incident $incident): ?string
    {
        if (! is_string($incident->report_pdf_path) || $incident->report_pdf_path === '') {
            return null;
        }

        return Storage::disk('local')->exists($incident->report_pdf_path)
            ? Storage::disk('local')->get($incident->report_pdf_path)
            : null;
    }

    public function filename(Incident $incident): string
    {
        return Str::slug($incident->reference.'-'.$incident->title).'.pdf';
    }

    private function reportPath(Incident $incident): string
    {
        return 'incident-reports/'.$incident->id.'/'.$this->filename($incident);
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
                    'user_name' => $this->recipientName($recipient),
                    'user_email' => $this->recipientEmail($recipient),
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
            ->sortBy(fn (array $row) => strtolower((string) ($row['user_name'] ?? '')))
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
                    'label' => $this->recipientName($recipient).' - '.$this->responseLabel($recipient->response_status),
                    'message' => $recipient->response_note,
                    'created_at' => $recipient->responded_at ?? $recipient->notified_at ?? $dispatch->sent_at ?? $dispatch->created_at,
                ];
            }

            foreach ($dispatch->messages as $message) {
                $items[] = [
                    'id' => $message->id,
                    'type' => 'Nadere info',
                    'label' => 'Nadere info'.($this->messageSenderName($message) ? ' - '.$this->messageSenderName($message) : ''),
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
                    'label' => $this->statusUserName($status).' - '.$this->operatorStatusLabel($status->status),
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
                'snapshot_data_uri' => null,
                'snapshot_available' => false,
                'aeret_snapshot_data_uri' => null,
                'aeret_url' => null,
                'openstreetmap_url' => null,
            ];
        }

        $flightMap = is_array($droneFlightContext['map'] ?? null) ? $droneFlightContext['map'] : [];
        $aeretUrl = $this->aeretReportUrl($latitude, $longitude);
        if ($aeretUrl === null && is_string($flightMap['aeret_url'] ?? null)) {
            $aeretUrl = $flightMap['aeret_url'];
        }
        try {
            $mapSnapshot = $this->satelliteMapSnapshot($latitude, $longitude);
        } catch (Throwable $exception) {
            report($exception);
            $mapSnapshot = [
                'available' => false,
                'data_uri' => null,
            ];
        }

        return [
            'available' => true,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'latitude_label' => number_format($latitude, 6, '.', ''),
            'longitude_label' => number_format($longitude, 6, '.', ''),
            'marker_x' => 50.0,
            'marker_y' => 50.0,
            'snapshot_data_uri' => $mapSnapshot['data_uri'],
            'snapshot_available' => $mapSnapshot['available'],
            'aeret_snapshot_data_uri' => null,
            'aeret_url' => $aeretUrl,
            'openstreetmap_url' => sprintf(
                'https://www.openstreetmap.org/?mlat=%1$.6f&mlon=%2$.6f#map=16/%1$.6f/%2$.6f',
                $latitude,
                $longitude,
            ),
        ];
    }

    /**
     * @return array{available: bool, data_uri: string|null}
     */
    private function satelliteMapSnapshot(float $latitude, float $longitude): array
    {
        $zoom = 16;
        $tileSize = 256;
        $width = 720;
        $height = 260;
        $scale = (2 ** $zoom) * $tileSize;
        $sinLatitude = sin(deg2rad(max(-85.05112878, min(85.05112878, $latitude))));
        $centerX = (($longitude + 180) / 360) * $scale;
        $centerY = (0.5 - log((1 + $sinLatitude) / (1 - $sinLatitude)) / (4 * M_PI)) * $scale;
        $left = $centerX - ($width / 2);
        $top = $centerY - ($height / 2);
        $firstTileX = (int) floor($left / $tileSize);
        $lastTileX = (int) floor(($left + $width) / $tileSize);
        $firstTileY = (int) floor($top / $tileSize);
        $lastTileY = (int) floor(($top + $height) / $tileSize);
        $maxTile = (2 ** $zoom) - 1;
        $tiles = [];
        $baseTiles = 0;
        $cacheKey = sha1(implode('|', [
            'satellite-labels',
            $zoom,
            $width,
            $height,
            number_format($latitude, 5, '.', ''),
            number_format($longitude, 5, '.', ''),
        ]));
        $cachePath = 'report-map-snapshots/'.$cacheKey.'.svg';

        if (Storage::disk('local')->exists($cachePath)) {
            return [
                'available' => true,
                'data_uri' => 'data:image/svg+xml;base64,'.base64_encode(Storage::disk('local')->get($cachePath)),
            ];
        }

        for ($tileX = $firstTileX; $tileX <= $lastTileX; $tileX++) {
            for ($tileY = $firstTileY; $tileY <= $lastTileY; $tileY++) {
                if ($tileY < 0 || $tileY > $maxTile) {
                    continue;
                }

                $wrappedTileX = (($tileX % ($maxTile + 1)) + ($maxTile + 1)) % ($maxTile + 1);
                foreach ($this->mapTileUrls($zoom, $tileY, $wrappedTileX) as $index => $url) {
                    $response = Http::timeout(4)
                        ->retry(1, 150)
                        ->withHeaders([
                            'User-Agent' => 'DIS Incident Report/1.0 (https://dis.wrdmarco.nl)',
                        ])
                        ->get($url);

                    if (! $response->ok()) {
                        continue;
                    }

                    if ($index === 0) {
                        $baseTiles++;
                    }

                    $tiles[] = [
                        'data_uri' => 'data:image/png;base64,'.base64_encode($response->body()),
                        'left' => round(($tileX * $tileSize) - $left, 2),
                        'top' => round(($tileY * $tileSize) - $top, 2),
                    ];
                }
            }
        }

        if ($baseTiles === 0) {
            return [
                'available' => false,
                'data_uri' => null,
            ];
        }

        $svg = $this->mapSnapshotSvg($width, $height, $tiles);
        Storage::disk('local')->put($cachePath, $svg);

        return [
            'available' => true,
            'data_uri' => 'data:image/svg+xml;base64,'.base64_encode($svg),
        ];
    }

    /**
     * @param array<int, array{data_uri: string, left: float, top: float}> $tiles
     */
    private function mapSnapshotSvg(int $width, int $height, array $tiles): string
    {
        $images = collect($tiles)
            ->map(fn (array $tile): string => sprintf(
                '<image href="%s" x="%s" y="%s" width="256" height="256" preserveAspectRatio="none" />',
                e($tile['data_uri']),
                number_format($tile['left'], 2, '.', ''),
                number_format($tile['top'], 2, '.', ''),
            ))
            ->implode('');

        $centerX = number_format($width / 2, 2, '.', '');
        $centerY = number_format($height / 2, 2, '.', '');
        $labelY = $height - 17;
        $labelTextY = $height - 8;
        $attributionX = $width - 132;
        $attributionY = $height - 21;
        $attributionTextX = $width - 96;
        $attributionTextY = $height - 9;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="{$width}" height="{$height}" fill="#e8f3f8"/>
  {$images}
  <circle cx="{$centerX}" cy="{$centerY}" r="16" fill="none" stroke="#dc2626" stroke-width="2"/>
  <circle cx="{$centerX}" cy="{$centerY}" r="8" fill="#dc2626" stroke="#ffffff" stroke-width="3"/>
  <rect x="10" y="{$labelY}" width="94" height="22" rx="5" fill="#ffffff" stroke="#dbe5f0"/>
  <text x="18" y="{$labelTextY}" font-family="DejaVu Sans, Arial, sans-serif" font-size="10" fill="#0f172a">Incidentlocatie</text>
  <rect x="{$attributionX}" y="{$attributionY}" width="122" height="18" rx="4" fill="#ffffff" fill-opacity="0.92"/>
  <text x="{$attributionTextX}" y="{$attributionTextY}" font-family="DejaVu Sans, Arial, sans-serif" font-size="8" fill="#475569">Esri Imagery + labels</text>
</svg>
SVG;
    }

    /**
     * @return array<int, string>
     */
    private function mapTileUrls(int $zoom, int $tileY, int $tileX): array
    {
        return [
            sprintf('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/%d/%d/%d', $zoom, $tileY, $tileX),
            sprintf('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/%d/%d/%d', $zoom, $tileY, $tileX),
            sprintf('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/%d/%d/%d', $zoom, $tileY, $tileX),
        ];
    }

    private function aeretReportUrl(float $latitude, float $longitude): ?string
    {
        [$x, $y] = $this->wgs84ToRd($latitude, $longitude);

        return 'https://aeret.kaartviewer.nl/?@dpf_basic&'.http_build_query([
            'catalogus' => '1',
            'v' => '5',
            'website' => 'dpf_basic',
            'x' => round($x, 2),
            'y' => round($y, 2),
            'zoom' => '9',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function wgs84ToRd(float $latitude, float $longitude): array
    {
        $referenceLatitude = 52.15517440;
        $referenceLongitude = 5.38720621;
        $dLatitude = 0.36 * ($latitude - $referenceLatitude);
        $dLongitude = 0.36 * ($longitude - $referenceLongitude);

        $x = 155000
            + (190094.945 * $dLongitude)
            + (-11832.228 * $dLatitude * $dLongitude)
            + (-114.221 * $dLatitude ** 2 * $dLongitude)
            + (-32.391 * $dLongitude ** 3)
            + (-0.705 * $dLatitude)
            + (-2.34 * $dLatitude ** 3 * $dLongitude)
            + (-0.608 * $dLatitude * $dLongitude ** 3)
            + (-0.008 * $dLongitude ** 2)
            + (0.148 * $dLatitude ** 2 * $dLongitude ** 3);

        $y = 463000
            + (309056.544 * $dLatitude)
            + (3638.893 * $dLongitude ** 2)
            + (73.077 * $dLatitude ** 2)
            + (-157.984 * $dLatitude * $dLongitude ** 2)
            + (59.788 * $dLatitude ** 3)
            + (0.433 * $dLongitude)
            + (-6.439 * $dLatitude ** 2 * $dLongitude ** 2)
            + (-0.032 * $dLatitude * $dLongitude)
            + (0.092 * $dLongitude ** 4)
            + (-0.054 * $dLatitude ** 4);

        return [$x, $y];
    }

    private function coordinate(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) ? $coordinate : null;
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

    private function recipientName(mixed $recipient): string
    {
        return $recipient->user?->name
            ?? (is_string($recipient->user_name ?? null) && $recipient->user_name !== '' ? $recipient->user_name : 'Verwijderde gebruiker');
    }

    private function recipientEmail(mixed $recipient): ?string
    {
        return $recipient->user?->email
            ?? (is_string($recipient->user_email ?? null) && $recipient->user_email !== '' ? $recipient->user_email : null);
    }

    private function statusUserName(AvailabilityStatus $status): string
    {
        return $status->user?->name
            ?? (is_string($status->user_name) && $status->user_name !== '' ? $status->user_name : 'Verwijderde gebruiker');
    }

    private function messageSenderName(mixed $message): ?string
    {
        return $message->sender?->name
            ?? (is_string($message->sent_by_name ?? null) && $message->sent_by_name !== '' ? $message->sent_by_name : null);
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
