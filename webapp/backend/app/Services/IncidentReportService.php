<?php

namespace App\Services;

use App\Models\AvailabilityStatus;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\SystemSetting;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
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
        $tempDir = $this->writableReportDirectory();
        $fontDir = $tempDir;

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', $tempDir);
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);

        $data = $this->data($incident);
        try {
            return $this->renderPdf($options, $data);
        } catch (Throwable $exception) {
            $this->safeReport($exception);
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
        $this->ensureWritableDirectory(storage_path('framework/views'));

        set_error_handler(static function (int $severity, string $message): bool {
            if (str_contains($message, 'tempnam(): file created in the system')) {
                return true;
            }

            return false;
        });

        try {
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml(view('reports.incident', $data)->render());
            $dompdf->setPaper('a4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        } finally {
            restore_error_handler();
        }
    }

    private function writableReportDirectory(): string
    {
        $candidates = [
            sys_get_temp_dir(),
            storage_path('tmp/report-render'),
            storage_path('app/report-temp'),
        ];

        foreach ($candidates as $path) {
            try {
                File::ensureDirectoryExists($path, 0770, true);
                @chmod($path, 0770);
                $probe = @tempnam($path, 'dis-report-probe-');
                if (is_string($probe) && str_starts_with($probe, rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                    @unlink($probe);

                    return $path;
                }
                if (is_string($probe)) {
                    @unlink($probe);
                }
            } catch (Throwable) {
                // Try the next candidate.
            }
        }

        return sys_get_temp_dir();
    }

    private function ensureWritableDirectory(string $path): void
    {
        try {
            File::ensureDirectoryExists($path, 0770, true);
            @chmod($path, 0770);
        } catch (Throwable) {
            // Let the normal render error handling report the real failure.
        }
    }

    public function ensureStored(Incident $incident): ?string
    {
        if (! in_array($incident->status, ['resolved', 'cancelled'], true)) {
            return null;
        }

        if (is_string($incident->report_pdf_path)
            && $incident->report_pdf_path !== ''
            && $this->storedReportExists($incident->report_pdf_path)) {
            return $incident->report_pdf_path;
        }

        try {
            $path = $this->reportPath($incident);
            $absolutePath = $this->absoluteReportPath($path);
            $this->ensureWritableDirectory(dirname($absolutePath));
            if (File::put($absolutePath, $this->pdf($incident)) === false) {
                throw new \RuntimeException('Incidentrapport kon niet worden opgeslagen op '.$absolutePath.'.');
            }
            @chmod($absolutePath, 0660);
            $incident->forceFill([
                'report_pdf_path' => $path,
                'report_generated_at' => now(),
                'report_generation_error' => null,
            ])->save();

            return $path;
        } catch (Throwable $exception) {
            $this->safeReport($exception);
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

        try {
            $path = $this->absoluteReportPath($incident->report_pdf_path);

            return is_file($path) && is_readable($path)
                ? (string) file_get_contents($path)
                : null;
        } catch (Throwable $exception) {
            $this->safeReport($exception);

            return null;
        }
    }

    public function storedPdfPath(Incident $incident): ?string
    {
        if (! is_string($incident->report_pdf_path) || $incident->report_pdf_path === '') {
            return null;
        }

        try {
            $path = $this->absoluteReportPath($incident->report_pdf_path);
            if (! is_file($path)) {
                return null;
            }

            return is_readable($path) ? $path : null;
        } catch (Throwable $exception) {
            $this->safeReport($exception);

            return null;
        }
    }

    public function filename(Incident $incident): string
    {
        return Str::slug($incident->reference.'-'.$incident->title).'.pdf';
    }

    private function reportPath(Incident $incident): string
    {
        return 'incident-reports/'.$incident->id.'/'.$this->filename($incident);
    }

    private function absoluteReportPath(string $path): string
    {
        return rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/').'/webapp/backend/storage/app/'.ltrim($path, '/');
    }

    private function storedReportExists(string $path): bool
    {
        try {
            $absolutePath = $this->absoluteReportPath($path);

            return is_file($absolutePath) && is_readable($absolutePath);
        } catch (Throwable $exception) {
            $this->safeReport($exception);

            return false;
        }
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
        $aeretUrl = is_string($flightMap['aeret_url'] ?? null)
            ? $flightMap['aeret_url']
            : $this->aeretReportUrl($latitude, $longitude);
        try {
            $mapSnapshot = $this->satelliteMapSnapshot($latitude, $longitude);
        } catch (Throwable $exception) {
            $this->safeReport($exception);
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
        $deadline = microtime(true) + 2.5;
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
        $cachePath = 'report-map-snapshots/'.$cacheKey.'.png';

        if (Storage::disk('local')->exists($cachePath)) {
            return [
                'available' => true,
                'data_uri' => 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($cachePath)),
            ];
        }

        if (! function_exists('imagecreatetruecolor')) {
            return [
                'available' => false,
                'data_uri' => null,
            ];
        }

        for ($tileX = $firstTileX; $tileX <= $lastTileX; $tileX++) {
            for ($tileY = $firstTileY; $tileY <= $lastTileY; $tileY++) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }

                if ($tileY < 0 || $tileY > $maxTile) {
                    continue;
                }

                $wrappedTileX = (($tileX % ($maxTile + 1)) + ($maxTile + 1)) % ($maxTile + 1);
                foreach ($this->mapTileUrls($zoom, $tileY, $wrappedTileX) as $index => $url) {
                    if (microtime(true) >= $deadline) {
                        break 2;
                    }

                    try {
                        $response = Http::timeout(1)
                            ->withHeaders([
                                'User-Agent' => 'DIS Incident Report/1.0 (https://dis.wrdmarco.nl)',
                            ])
                            ->get($url);
                    } catch (Throwable) {
                        continue;
                    }

                    if (! $response->ok()) {
                        continue;
                    }

                    if ($index === 0) {
                        $baseTiles++;
                    }

                    $tiles[] = [
                        'body' => $response->body(),
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

        $png = $this->mapSnapshotPng($width, $height, $tiles);
        if ($png === null) {
            return [
                'available' => false,
                'data_uri' => null,
            ];
        }

        Storage::disk('local')->put($cachePath, $png);

        return [
            'available' => true,
            'data_uri' => 'data:image/png;base64,'.base64_encode($png),
        ];
    }

    /**
     * @param array<int, array{body: string, left: float, top: float}> $tiles
     */
    private function mapSnapshotPng(int $width, int $height, array $tiles): ?string
    {
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            return null;
        }

        $background = imagecolorallocate($canvas, 232, 243, 248);
        $red = imagecolorallocate($canvas, 220, 38, 38);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $text = imagecolorallocate($canvas, 15, 23, 42);
        $muted = imagecolorallocate($canvas, 71, 85, 105);
        $border = imagecolorallocate($canvas, 219, 229, 240);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $background);

        foreach ($tiles as $tile) {
            $image = @imagecreatefromstring($tile['body']);
            if ($image === false) {
                continue;
            }

            imagecopy($canvas, $image, (int) round($tile['left']), (int) round($tile['top']), 0, 0, 256, 256);
            imagedestroy($image);
        }

        $centerX = (int) round($width / 2);
        $centerY = (int) round($height / 2);
        imageellipse($canvas, $centerX, $centerY, 32, 32, $red);
        imagefilledellipse($canvas, $centerX, $centerY, 16, 16, $red);
        imageellipse($canvas, $centerX, $centerY, 19, 19, $white);

        imagefilledrectangle($canvas, 10, $height - 29, 104, $height - 7, $white);
        imagerectangle($canvas, 10, $height - 29, 104, $height - 7, $border);
        imagestring($canvas, 2, 18, $height - 24, 'Incidentlocatie', $text);

        imagefilledrectangle($canvas, $width - 132, $height - 25, $width - 10, $height - 7, $white);
        imagestring($canvas, 1, $width - 96, $height - 21, 'Esri Imagery + labels', $muted);

        ob_start();
        imagepng($canvas, null, 6);
        $png = ob_get_clean();
        imagedestroy($canvas);

        return is_string($png) && $png !== '' ? $png : null;
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
        $configured = trim(SystemSetting::string('drone.aeret_map_url', (string) config('dis.drone_flight.aeret_map_url')) ?? '');
        if ($configured === '') {
            return null;
        }

        return $this->aeretUrlWithCoordinates($configured, $latitude, $longitude);
    }

    private function aeretUrlWithCoordinates(string $url, float $latitude, float $longitude): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        if (! str_contains($url, 'aeret.kaartviewer.nl')) {
            $separator = str_contains($url, '?') ? '&' : '?';

            return $url.$separator.http_build_query([
                'lat' => round($latitude, 7),
                'lon' => round($longitude, 7),
            ], '', '&', PHP_QUERY_RFC3986);
        }

        [$x, $y] = $this->wgs84ToRd($latitude, $longitude);
        $parts = parse_url($url);
        $query = [];
        if (is_string($parts['query'] ?? null) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }

        unset($query['@dpf_basic']);
        $query = array_merge($query, [
            'catalogus' => '1',
            'v' => '5',
            'website' => 'dpf_basic',
            'x' => round($x, 2),
            'y' => round($y, 2),
            'zoom' => '9',
        ]);

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'aeret.kaartviewer.nl';
        $path = $parts['path'] ?? '/';
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $scheme.'://'.$host.$path.'?@dpf_basic'.($queryString === '' ? '' : '&'.$queryString);
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

    private function safeReport(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // A logging backend failure must not block incident report generation.
        }
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
