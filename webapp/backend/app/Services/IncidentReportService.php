<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AvailabilityStatus;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\PilotIncidentReport;
use App\Models\SystemSetting;
use App\Support\IncidentTimelineAttribution;
use App\Support\IncidentTimelineResponsePresentation;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

final class IncidentReportService
{
    private const PILOT_STANDARD_FIELD_KEYS = [
        'summary',
        'observations',
        'actions_taken',
        'result',
        'issues',
        'equipment_used',
        'flight_time',
        'flight_minutes',
    ];

    public function __construct(
        private readonly DroneFlightContextService $droneFlightContextService,
        private readonly PilotIncidentReportFormService $pilotReportFormService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(Incident $incident, bool $preserveExistingMaps = false): array
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
        $pilotReports = $incident->pilotReports()
            ->with(['user' => fn ($query) => $query->withTrashed()])
            ->where('status', 'submitted')
            ->oldest('submitted_at')
            ->oldest()
            ->get();

        return [
            'incident' => $incident,
            'dispatches' => $dispatches,
            'travelRows' => $travelRows,
            'pilotReports' => $pilotReports,
            'pilotReportRows' => $this->pilotReportRows($pilotReports),
            'timeline' => $timeline,
            'map' => $this->mapData($incident, is_array($droneFlightContext) ? $droneFlightContext : null, $preserveExistingMaps),
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

    /**
     * @param  Collection<int, PilotIncidentReport>  $pilotReports
     * @return Collection<int, array<string, mixed>>
     */
    private function pilotReportRows(Collection $pilotReports): Collection
    {
        return $pilotReports->map(function (PilotIncidentReport $report): array {
            $customFields = is_array($report->custom_fields) ? $report->custom_fields : [];
            $fields = collect($this->pilotReportFormService->fields($report->user))
                ->keyBy(fn (array $field): string => (string) $field['key']);

            return [
                'user_name' => $report->user_name ?: 'Verwijderde gebruiker',
                'user_email' => $report->user_email ?: '-',
                'submitted_at' => $report->submitted_at,
                'flight_minutes' => $report->flight_minutes ?? $this->flightMinutesFromCustomValue($customFields['flight_time'] ?? null),
                'summary' => $this->standardPilotText($report->summary, $customFields, 'summary'),
                'observations' => $this->standardPilotText($report->observations, $customFields, 'observations'),
                'actions_taken' => $this->standardPilotText($report->actions_taken, $customFields, 'actions_taken'),
                'result' => $this->standardPilotText($report->result, $customFields, 'result'),
                'issues' => $this->standardPilotText($report->issues, $customFields, 'issues'),
                'equipment_used' => $this->standardPilotText($report->equipment_used, $customFields, 'equipment_used'),
                'extra_fields' => $this->pilotExtraFields($customFields, $fields),
            ];
        })->values();
    }

    /**
     * @param  array<string, mixed>  $customFields
     * @param  Collection<string, array<string, mixed>>  $fields
     * @return array<int, array{label: string, value: string}>
     */
    private function pilotExtraFields(array $customFields, Collection $fields): array
    {
        $rows = [];
        foreach ($customFields as $key => $value) {
            if (in_array($key, self::PILOT_STANDARD_FIELD_KEYS, true)) {
                continue;
            }

            $field = $fields->get($key);
            if (is_array($field) && ($field['type'] ?? null) === 'section') {
                continue;
            }

            $formatted = $this->pilotCustomDisplayValue(is_array($field) ? $field : null, $value);
            if ($formatted === '-') {
                continue;
            }

            $rows[] = [
                'label' => is_array($field) ? (string) ($field['label'] ?? $key) : Str::headline(str_replace('_', ' ', (string) $key)),
                'value' => $formatted,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $field
     */
    private function pilotCustomDisplayValue(?array $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (($field['option_source'] ?? 'manual') === 'user_drones' && is_scalar($value)) {
            return $this->assetLabelById((string) $value) ?? (string) $value;
        }

        if (is_array($field) && in_array($field['type'] ?? null, ['select', 'radio'], true)) {
            foreach (($field['options'] ?? []) as $option) {
                if (is_array($option) && (string) ($option['value'] ?? '') === (string) $value) {
                    return (string) ($option['label'] ?? $value);
                }
            }
        }

        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nee';
        }

        if (is_array($value)) {
            if (array_key_exists('start', $value) || array_key_exists('end', $value)) {
                $start = trim((string) ($value['start'] ?? ''));
                $end = trim((string) ($value['end'] ?? ''));
                $duration = isset($value['duration_minutes']) && is_numeric($value['duration_minutes'])
                    ? ' ('.(int) $value['duration_minutes'].' min)'
                    : '';
                $range = trim($start.($start !== '' && $end !== '' ? ' - ' : '').$end);

                return $range === '' ? '-' : $range.$duration;
            }

            $parts = array_filter(array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $value));

            return $parts === [] ? '-' : implode(', ', $parts);
        }

        $text = trim((string) $value);

        return $text === '' ? '-' : $text;
    }

    private function standardPilotText(mixed $storedValue, array $customFields, string $key): ?string
    {
        $value = is_scalar($storedValue) ? trim((string) $storedValue) : '';
        if ($value !== '') {
            return $value;
        }

        $customValue = $customFields[$key] ?? null;
        if (! is_scalar($customValue)) {
            return null;
        }

        $text = trim((string) $customValue);

        return $text === '' ? null : $text;
    }

    private function flightMinutesFromCustomValue(mixed $value): ?int
    {
        if (is_array($value) && isset($value['duration_minutes']) && is_numeric($value['duration_minutes'])) {
            return (int) $value['duration_minutes'];
        }

        return null;
    }

    private function assetLabelById(string $assetId): ?string
    {
        $asset = Asset::query()->with('droneType')->find($assetId);
        if (! $asset instanceof Asset) {
            return null;
        }

        $name = trim($asset->name);
        $type = trim($asset->droneType ? $asset->droneType->manufacturer.' '.$asset->droneType->model : '');

        if ($type === '' || strcasecmp($name, $type) === 0) {
            return $name !== '' ? $name : 'Drone';
        }

        return trim(($name !== '' ? $name : 'Drone').' ('.$type.')');
    }

    public function pdf(Incident $incident, bool $preserveExistingMaps = false): string
    {
        $tempDir = $this->writableReportDirectory();
        $fontDir = $tempDir;

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', $tempDir);
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);

        $data = $this->data($incident, $preserveExistingMaps);
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
     * @param  array<string, mixed>  $data
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
            $dompdf->loadHtml($this->renderReportHtml($data));
            $dompdf->setPaper('a4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderReportHtml(array $data): string
    {
        try {
            return view('reports.incident', $data)->render();
        } catch (Throwable $exception) {
            if (! $this->isMissingCompiledReportView($exception)) {
                throw $exception;
            }

            $this->safeReport($exception);
            $this->compileReportView();

            return view('reports.incident', $data)->render();
        }
    }

    private function isMissingCompiledReportView(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'File does not exist at path')
            && str_contains($message, 'storage/framework/views')
            && str_contains($message, 'reports/incident.blade.php');
    }

    private function compileReportView(): void
    {
        $compiledPath = (string) config('view.compiled', storage_path('framework/views'));
        $this->ensureWritableDirectory($compiledPath);

        $viewPath = resource_path('views/reports/incident.blade.php');
        app('blade.compiler')->compile($viewPath);
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

        $hasStoredReport = is_string($incident->report_pdf_path)
            && $incident->report_pdf_path !== ''
            && $this->storedReportExists($incident->report_pdf_path);

        if ($hasStoredReport && $incident->report_finalized_at !== null) {
            return $incident->report_pdf_path;
        }

        if ($hasStoredReport && ! $this->reportCanBeFinalized($incident)) {
            return $incident->report_pdf_path;
        }

        return $this->storePdf($incident, $hasStoredReport);
    }

    public function refreshStored(Incident $incident, bool $preserveExistingMaps = false): ?string
    {
        if (! in_array($incident->status, ['resolved', 'cancelled'], true)) {
            return null;
        }

        if (is_string($incident->report_pdf_path)
            && $incident->report_pdf_path !== ''
            && $this->storedReportExists($incident->report_pdf_path)
            && $incident->report_finalized_at !== null) {
            return $incident->report_pdf_path;
        }

        return $this->storePdf($incident, $preserveExistingMaps);
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

    private function storePdf(Incident $incident, bool $preserveExistingMaps = false): ?string
    {
        try {
            $path = $this->reportPath($incident);
            $isFinal = $this->reportCanBeFinalized($incident);
            $absolutePath = $this->absoluteReportPath($path);
            $this->ensureWritableDirectory(dirname($absolutePath));
            if (File::put($absolutePath, $this->pdf($incident, $preserveExistingMaps)) === false) {
                throw new \RuntimeException('Incidentrapport kon niet worden opgeslagen op '.$absolutePath.'.');
            }
            @chmod($absolutePath, 0660);
            $incident->forceFill([
                'report_pdf_path' => $path,
                'report_generated_at' => now(),
                'report_finalized_at' => $isFinal ? now() : null,
                'report_generation_error' => null,
            ])->save();

            return $path;
        } catch (Throwable $exception) {
            $this->safeReport($exception);
            $incident->forceFill([
                'report_generation_error' => 'Report generation failed. See secured server logs.',
            ])->save();

            return null;
        }
    }

    public function reportCanBeFinalized(Incident $incident): bool
    {
        return $this->missingPilotReportCount($incident) === 0
            && $this->unfinalizedPilotReportCount($incident) === 0;
    }

    public function missingPilotReportCount(Incident $incident): int
    {
        $acceptedUserIds = $this->acceptedPilotReportUserIds($incident);
        if ($acceptedUserIds->isEmpty()) {
            return 0;
        }

        $submittedUserIds = $incident->pilotReports()
            ->where('status', 'submitted')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->values();

        return $acceptedUserIds
            ->diff($submittedUserIds)
            ->count();
    }

    public function unfinalizedPilotReportCount(Incident $incident): int
    {
        $acceptedUserIds = $this->acceptedPilotReportUserIds($incident);
        if ($acceptedUserIds->isEmpty()) {
            return 0;
        }

        return $incident->pilotReports()
            ->where('status', 'submitted')
            ->whereIn('user_id', $acceptedUserIds->all())
            ->get()
            ->filter(fn (PilotIncidentReport $report): bool => ! $report->isFinalized())
            ->count();
    }

    /**
     * @return Collection<int, string>
     */
    private function acceptedPilotReportUserIds(Incident $incident): Collection
    {
        $dispatches = $incident->dispatchRequests()
            ->with('recipients')
            ->whereIn('status', ['sent', 'escalated'])
            ->get();

        return $dispatches
            ->flatMap(fn (DispatchRequest $dispatch) => $dispatch->recipients)
            ->filter(fn ($recipient): bool => $recipient->response_status === 'accepted'
                && is_string($recipient->user_id)
                && $recipient->user_id !== '')
            ->pluck('user_id')
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, DispatchRequest>  $dispatches
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
     * @param  Collection<int, DispatchRequest>  $dispatches
     * @return Collection<int, array<string, mixed>>
     */
    private function timeline(Incident $incident, Collection $dispatches): Collection
    {
        $statusItems = $incident->statusHistory()
            ->oldest('created_at')
            ->get()
            ->map(function ($item): array {
                $statusChange = trim(($item->from_status ?? 'nieuw').' -> '.$item->to_status);

                return [
                    'id' => $item->id,
                    'type' => 'Incidentstatus',
                    'label' => $statusChange,
                    'message' => $item->reason,
                    'created_at' => $item->created_at,
                    ...IncidentTimelineAttribution::make(
                        $item->changed_by,
                        $item->changed_by_name,
                        'Incidentstatus gewijzigd: '.$statusChange,
                        'Niet vastgelegd',
                    ),
                ];
            });

        $dispatchItems = $dispatches->flatMap(function (DispatchRequest $dispatch): array {
            $items = [[
                'id' => $dispatch->id,
                'type' => 'Alarmering',
                'label' => 'Dispatch '.$dispatch->status,
                'message' => $dispatch->message,
                'created_at' => $dispatch->created_at,
                ...IncidentTimelineAttribution::make(
                    $dispatch->requested_by,
                    $dispatch->requested_by_name,
                    'Alarmering aangemaakt',
                    'Niet vastgelegd',
                ),
            ]];

            foreach ($dispatch->recipients as $recipient) {
                $recipientName = $this->recipientName($recipient);
                $responseState = IncidentTimelineResponsePresentation::currentState($recipient, $dispatch);
                $items[] = [
                    'id' => $recipient->id,
                    'type' => 'Opkomst',
                    'label' => $recipientName.' - '.$responseState['response_label'],
                    'message' => $recipient->response_note,
                    'created_at' => $responseState['occurred_at'],
                    'actor' => $responseState['actor'],
                    'actor_name' => $responseState['actor_name'],
                    'description' => $responseState['description'],
                ];
            }

            foreach ($dispatch->messages as $message) {
                $senderName = $this->messageSenderName($message);
                $items[] = [
                    'id' => $message->id,
                    'type' => 'Nadere info',
                    'label' => 'Nadere info'.($senderName ? ' - '.$senderName : ''),
                    'message' => $message->body,
                    'created_at' => $message->created_at,
                    ...IncidentTimelineAttribution::make(
                        $message->sent_by,
                        $senderName,
                        'Nadere informatie toegevoegd',
                        'Niet vastgelegd',
                    ),
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
                ->map(function (AvailabilityStatus $status): array {
                    $userName = $this->statusUserName($status);
                    $statusLabel = $this->operatorStatusLabel($status->status);

                    return [
                        'id' => $status->id,
                        'type' => 'Operationele status',
                        'label' => $userName.' - '.$statusLabel,
                        'message' => $status->reason,
                        'created_at' => $status->effective_at,
                        ...IncidentTimelineAttribution::make(
                            $status->changed_by,
                            $status->changed_by_name,
                            'Operationele status van '.$userName.' gewijzigd naar '.$statusLabel,
                            $status->is_system_applied ? 'Systeem' : 'Niet vastgelegd',
                        ),
                    ];
                });
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
    private function mapData(Incident $incident, ?array $droneFlightContext, bool $preserveExistingMaps = false): array
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
            $mapSnapshot = $this->satelliteMapSnapshot($latitude, $longitude, $preserveExistingMaps);
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
            'aeret_snapshot_data_uri' => $this->aeretMapSnapshot($aeretUrl, $preserveExistingMaps),
            'aeret_url' => $aeretUrl,
            'openstreetmap_url' => sprintf(
                'https://www.openstreetmap.org/?mlat=%1$.6f&mlon=%2$.6f#map=16/%1$.6f/%2$.6f',
                $latitude,
                $longitude,
            ),
        ];
    }

    private function aeretMapSnapshot(?string $aeretUrl, bool $cacheOnly = false): ?string
    {
        if (! is_string($aeretUrl) || trim($aeretUrl) === '') {
            return null;
        }

        $cacheKey = sha1('aeret-report-clean-v4|'.$aeretUrl);
        $cachePath = $this->absoluteReportSupportPath('report-map-snapshots/aeret-'.$cacheKey.'.png');
        if (is_file($cachePath) && is_readable($cachePath)) {
            return 'data:image/png;base64,'.base64_encode((string) file_get_contents($cachePath));
        }

        if ($cacheOnly) {
            return null;
        }

        $script = realpath(base_path('../../scripts/aeret-snapshot.mjs'));
        if (! is_string($script) || ! is_file($script)) {
            return null;
        }

        $node = (string) (env('NODE_BINARY') ?: 'node');
        $temporaryPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dis-aeret-'.$cacheKey.'.png';

        try {
            @unlink($temporaryPath);
            $result = Process::timeout(120)->run([$node, $script, $aeretUrl, $temporaryPath, '1200', '720']);
            if (! $result->successful() || ! is_file($temporaryPath) || filesize($temporaryPath) === 0) {
                $this->safeReport(new \RuntimeException('Aeret snapshot failed: '.mb_substr(trim($result->errorOutput() ?: $result->output() ?: 'no output'), 0, 1000)));

                return null;
            }

            $this->ensureWritableDirectory(dirname($cachePath));
            File::copy($temporaryPath, $cachePath);
            @chmod($cachePath, 0660);

            return 'data:image/png;base64,'.base64_encode((string) file_get_contents($cachePath));
        } catch (Throwable $exception) {
            $this->safeReport($exception);

            return null;
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function absoluteReportSupportPath(string $path): string
    {
        return rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/').'/webapp/backend/storage/app/'.ltrim($path, '/');
    }

    /**
     * @return array{available: bool, data_uri: string|null}
     */
    private function satelliteMapSnapshot(float $latitude, float $longitude, bool $cacheOnly = false): array
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
        $cachePath = $this->absoluteReportSupportPath('report-map-snapshots/'.$cacheKey.'.png');

        if (is_file($cachePath) && is_readable($cachePath)) {
            return [
                'available' => true,
                'data_uri' => 'data:image/png;base64,'.base64_encode((string) file_get_contents($cachePath)),
            ];
        }

        if ($cacheOnly) {
            return [
                'available' => false,
                'data_uri' => null,
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

        try {
            $this->ensureWritableDirectory(dirname($cachePath));
            if (File::put($cachePath, $png) !== false) {
                @chmod($cachePath, 0660);
            }
        } catch (Throwable) {
            // Snapshot caching is best-effort; the report can still embed this render directly.
        }

        return [
            'available' => true,
            'data_uri' => 'data:image/png;base64,'.base64_encode($png),
        ];
    }

    /**
     * @param  array<int, array{body: string, left: float, top: float}>  $tiles
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
            'catalogus' => '0',
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
