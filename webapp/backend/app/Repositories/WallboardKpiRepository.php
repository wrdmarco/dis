<?php

namespace App\Repositories;

use App\Models\Asset;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\PilotIncidentReport;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class WallboardKpiRepository
{
    /** @var array<string, string> */
    private const PROVINCES = [
        '20' => 'Groningen',
        '21' => 'Fryslân',
        '22' => 'Drenthe',
        '23' => 'Overijssel',
        '24' => 'Flevoland',
        '25' => 'Gelderland',
        '26' => 'Utrecht',
        '27' => 'Noord-Holland',
        '28' => 'Zuid-Holland',
        '29' => 'Zeeland',
        '30' => 'Noord-Brabant',
        '31' => 'Limburg',
    ];

    /** @var array<string, string> */
    private const COUNTRIES = [
        'NL' => 'Nederland',
        'BE' => 'België',
        'DE' => 'Duitsland',
    ];

    /** @return Collection<int, User> */
    public function pilots(): Collection
    {
        return User::query()
            ->where('account_status', 'active')
            ->whereHas('teams', fn ($teams) => $teams->where('teams.code', 'OCP'))
            ->whereHas('roles', fn ($roles) => $roles->where('roles.name', 'operator-pilot'))
            ->with(['statuses' => fn ($statuses) => $statuses->latestPerUser()])
            ->get(['id', 'push_enabled']);
    }

    /**
     * @return array{total: int, by_status: array{active: int, dispatching: int, in_progress: int}, by_priority: array{low: int, normal: int, high: int, critical: int}}
     */
    public function activeIncidentCounts(): array
    {
        $counts = [
            'total' => 0,
            'by_status' => ['active' => 0, 'dispatching' => 0, 'in_progress' => 0],
            'by_priority' => ['low' => 0, 'normal' => 0, 'high' => 0, 'critical' => 0],
        ];

        $rows = Incident::query()
            ->where('is_test', false)
            ->whereIn('status', array_keys($counts['by_status']))
            ->select(['status', 'priority'])
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('status', 'priority')
            ->get();

        foreach ($rows as $row) {
            $aggregate = (int) $row->aggregate;
            $status = (string) $row->status;
            $priority = (string) $row->priority;
            $counts['total'] += $aggregate;
            if (array_key_exists($status, $counts['by_status'])) {
                $counts['by_status'][$status] += $aggregate;
            }
            if (array_key_exists($priority, $counts['by_priority'])) {
                $counts['by_priority'][$priority] += $aggregate;
            }
        }

        return $counts;
    }

    /**
     * @return array{registered_total: int, opened_today: int, resolved_today: int, cancelled_today: int, resolved_total: int, cancelled_total: int}
     */
    public function incidentLifecycleCounts(DateTimeInterface $dayStart, DateTimeInterface $dayEnd): array
    {
        $row = Incident::query()
            ->where('is_test', false)
            ->selectRaw('COUNT(*) AS registered_total')
            ->selectRaw(
                'SUM(CASE WHEN opened_at >= ? AND opened_at < ? THEN 1 ELSE 0 END) AS opened_today',
                [$dayStart, $dayEnd],
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'resolved' AND closed_at >= ? AND closed_at < ? THEN 1 ELSE 0 END) AS resolved_today",
                [$dayStart, $dayEnd],
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'cancelled' AND closed_at >= ? AND closed_at < ? THEN 1 ELSE 0 END) AS cancelled_today",
                [$dayStart, $dayEnd],
            )
            ->selectRaw("SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_total")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_total")
            ->first();

        return [
            'registered_total' => (int) ($row?->registered_total ?? 0),
            'opened_today' => (int) ($row?->opened_today ?? 0),
            'resolved_today' => (int) ($row?->resolved_today ?? 0),
            'cancelled_today' => (int) ($row?->cancelled_today ?? 0),
            'resolved_total' => (int) ($row?->resolved_total ?? 0),
            'cancelled_total' => (int) ($row?->cancelled_total ?? 0),
        ];
    }

    /** @return array{total: int, ready: int, maintenance: int, unavailable: int, issues: int, drones_total: int, drones_ready: int} */
    public function assetCounts(): array
    {
        $rows = Asset::query()
            ->select(['type', 'status'])
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('type', 'status')
            ->get();
        $total = 0;
        $ready = 0;
        $maintenance = 0;
        $unavailable = 0;
        $issues = 0;
        $dronesTotal = 0;
        $dronesReady = 0;
        foreach ($rows as $row) {
            $aggregate = (int) $row->aggregate;
            $status = (string) $row->status;
            $total += $aggregate;
            $ready += $status === 'ready' ? $aggregate : 0;
            $maintenance += $status === 'maintenance' ? $aggregate : 0;
            $unavailable += in_array($status, ['unavailable', 'retired'], true) ? $aggregate : 0;
            $issues += in_array($status, ['maintenance', 'unavailable'], true) ? $aggregate : 0;
            if ((string) $row->type === 'drone') {
                $dronesTotal += $aggregate;
                $dronesReady += $status === 'ready' ? $aggregate : 0;
            }
        }

        return [
            'total' => $total,
            'ready' => $ready,
            'maintenance' => $maintenance,
            'unavailable' => $unavailable,
            'issues' => $issues,
            'drones_total' => $dronesTotal,
            'drones_ready' => $dronesReady,
        ];
    }

    public function activeDispatchCount(): int
    {
        return DispatchRequest::query()
            ->whereIn('status', ['sent', 'escalated'])
            ->whereNotNull('sent_at')
            ->whereHas('incident', static fn ($incidents) => $incidents
                ->where('is_test', false)
                ->whereIn('status', ['active', 'dispatching', 'in_progress']))
            ->count();
    }

    /** @return Collection<int, DispatchRecipient> */
    public function activeResponseRecipients(): Collection
    {
        return DispatchRecipient::query()
            ->with('dispatchRequest:id,incident_id')
            ->whereHas('dispatchRequest', static fn ($dispatches) => $dispatches
                ->whereIn('status', ['sent', 'escalated'])
                ->whereNotNull('sent_at')
                ->whereHas('incident', static fn ($incidents) => $incidents
                    ->where('is_test', false)
                    ->whereIn('status', ['active', 'dispatching', 'in_progress'])))
            ->orderByDesc('updated_at')
            ->orderByRaw('CASE WHEN responded_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('responded_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'dispatch_request_id',
                'user_id',
                'response_status',
                'notified_at',
                'responded_at',
                'updated_at',
            ]);
    }

    /**
     * Counts submitted, non-test pilot reports with a positive flight duration.
     * The supplied boundaries are UTC instants for an Amsterdam calendar month.
     *
     * @return array{reports: int, minutes: int, average_minutes: float|null}
     */
    public function flightMetrics(DateTimeInterface $monthStartUtc, DateTimeInterface $monthEndUtc): array
    {
        $row = $this->flightReportQuery($monthStartUtc, $monthEndUtc)
            ->selectRaw('COUNT(*) AS reports')
            ->selectRaw('COALESCE(SUM(flight_minutes), 0) AS minutes')
            ->first();

        $reports = (int) ($row?->reports ?? 0);
        $minutes = (int) ($row?->minutes ?? 0);

        return [
            'reports' => $reports,
            'minutes' => $minutes,
            'average_minutes' => $reports === 0 ? null : round($minutes / $reports, 1),
        ];
    }

    /**
     * Every positive-flight report contributes exactly one segment. The stable
     * `drone_used` key wins when multiple historic user-drone fields exist.
     *
     * @param  list<string>  $fieldKeys
     * @return list<array{label: string, value: int}>
     */
    public function droneFlightDistribution(
        DateTimeInterface $monthStartUtc,
        DateTimeInterface $monthEndUtc,
        array $fieldKeys,
    ): array {
        $reports = $this->flightReportQuery($monthStartUtc, $monthEndUtc)
            ->get(['id', 'custom_fields', 'drone_usage_snapshot']);

        /** @var array<string, array{asset_id: string|null, label: string|null}> $choices */
        $choices = [];
        $unresolvedAssetIds = [];
        foreach ($reports as $report) {
            $customFields = is_array($report->custom_fields) ? $report->custom_fields : [];
            $snapshots = is_array($report->drone_usage_snapshot) ? $report->drone_usage_snapshot : [];
            $assetId = null;
            $snapshotLabel = null;
            foreach ($fieldKeys as $fieldKey) {
                $candidate = $customFields[$fieldKey] ?? null;
                if (! is_scalar($candidate) || trim((string) $candidate) === '') {
                    continue;
                }

                $assetId = trim((string) $candidate);
                $snapshot = is_array($snapshots[$fieldKey] ?? null) ? $snapshots[$fieldKey] : [];
                if (($snapshot['asset_id'] ?? null) === $assetId) {
                    $snapshotLabel = $this->droneTypeLabel(
                        $snapshot['manufacturer'] ?? null,
                        $snapshot['model'] ?? null,
                    );
                }
                break;
            }

            if ($assetId === null) {
                $historical = $this->historicalSnapshotChoice($customFields, $snapshots);
                if ($historical !== null) {
                    $assetId = $historical['asset_id'];
                    $snapshotLabel = $historical['label'];
                }
            }

            $choices[(string) $report->id] = ['asset_id' => $assetId, 'label' => $snapshotLabel];
            if ($assetId !== null && $snapshotLabel === null) {
                $unresolvedAssetIds[$assetId] = true;
            }
        }

        $assetLabels = [];
        if ($unresolvedAssetIds !== []) {
            Asset::query()
                ->withTrashed()
                ->with(['droneType' => static fn ($types) => $types->withTrashed()])
                ->whereKey(array_keys($unresolvedAssetIds))
                ->get()
                ->each(function (Asset $asset) use (&$assetLabels): void {
                    if ($asset->type !== 'drone') {
                        return;
                    }
                    $assetLabels[(string) $asset->id] = $this->droneTypeLabel(
                        $asset->droneType?->manufacturer,
                        $asset->droneType?->model,
                    );
                });
        }

        $counts = [];
        foreach ($choices as $choice) {
            $label = $choice['label']
                ?? ($choice['asset_id'] === null ? null : ($assetLabels[$choice['asset_id']] ?? null))
                ?? 'Onbekend';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $unknown = (int) ($counts['Onbekend'] ?? 0);
        unset($counts['Onbekend']);
        uksort($counts, static function (string $left, string $right) use ($counts): int {
            $byCount = $counts[$right] <=> $counts[$left];

            return $byCount !== 0 ? $byCount : strnatcasecmp($left, $right);
        });

        $segments = [];
        $other = 0;
        foreach ($counts as $label => $count) {
            if (count($segments) < 8) {
                $segments[] = ['label' => $label, 'value' => $count];
            } else {
                $other += $count;
            }
        }
        if ($other > 0) {
            $segments[] = ['label' => 'Overig', 'value' => $other];
        }
        if ($unknown > 0) {
            $segments[] = ['label' => 'Onbekend', 'value' => $unknown];
        }

        return $segments;
    }

    /**
     * Known Belgian and German incidents are outside the province denominator.
     * Unresolved country rows stay visible as `Onbekend` until enrichment finishes.
     *
     * @return list<array{label: string, value: int}>
     */
    public function incidentProvinceDistribution(): array
    {
        $rows = Incident::query()
            ->where('is_test', false)
            ->where(static fn (Builder $query) => $query
                ->whereNull('country_code')
                ->orWhere('country_code', 'NL'))
            ->select('province_code')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('province_code')
            ->get();

        $counts = array_fill_keys(array_keys(self::PROVINCES), 0);
        $unknown = 0;
        foreach ($rows as $row) {
            $code = is_string($row->province_code) ? strtoupper($row->province_code) : null;
            if ($code !== null && array_key_exists($code, $counts)) {
                $counts[$code] += (int) $row->aggregate;
            } else {
                $unknown += (int) $row->aggregate;
            }
        }

        $segments = [];
        foreach (self::PROVINCES as $code => $label) {
            $segments[] = ['label' => $label, 'value' => $counts[$code]];
        }
        $segments[] = ['label' => 'Onbekend', 'value' => $unknown];

        return $segments;
    }

    /** @return list<array{label: string, value: int}> */
    public function incidentCountryDistribution(): array
    {
        $rows = Incident::query()
            ->where('is_test', false)
            ->select('country_code')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('country_code')
            ->get();

        $counts = array_fill_keys(array_keys(self::COUNTRIES), 0);
        $unknown = 0;
        foreach ($rows as $row) {
            $code = is_string($row->country_code) ? strtoupper($row->country_code) : null;
            if ($code !== null && array_key_exists($code, $counts)) {
                $counts[$code] += (int) $row->aggregate;
            } else {
                $unknown += (int) $row->aggregate;
            }
        }

        $segments = [];
        foreach (self::COUNTRIES as $code => $label) {
            $segments[] = ['label' => $label, 'value' => $counts[$code]];
        }
        $segments[] = ['label' => 'Onbekend', 'value' => $unknown];

        return $segments;
    }

    private function flightReportQuery(DateTimeInterface $monthStartUtc, DateTimeInterface $monthEndUtc): Builder
    {
        return PilotIncidentReport::query()
            ->where('status', 'submitted')
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $monthStartUtc)
            ->where('submitted_at', '<', $monthEndUtc)
            ->where('flight_minutes', '>', 0)
            ->whereHas('incident', static fn (Builder $incidents) => $incidents->where('is_test', false));
    }

    private function droneTypeLabel(mixed $manufacturer, mixed $model): ?string
    {
        $manufacturer = trim(is_string($manufacturer) ? $manufacturer : '');
        $model = trim(is_string($model) ? $model : '');

        return $manufacturer !== '' && $model !== '' ? $manufacturer.' '.$model : null;
    }

    /**
     * A removed or retyped form field must not erase immutable flight history.
     * Only server snapshots that still match their stored selection qualify;
     * arbitrary custom field values are never interpreted as drone assets.
     *
     * @param  array<string, mixed>  $customFields
     * @param  array<string, mixed>  $snapshots
     * @return array{asset_id: string, label: string}|null
     */
    private function historicalSnapshotChoice(array $customFields, array $snapshots): ?array
    {
        $orderedKeys = array_key_exists('drone_used', $snapshots) ? ['drone_used'] : [];
        foreach ($snapshots as $fieldKey => $_snapshot) {
            if (is_string($fieldKey) && $fieldKey !== 'drone_used') {
                $orderedKeys[] = $fieldKey;
            }
        }

        foreach ($orderedKeys as $fieldKey) {
            $snapshot = $snapshots[$fieldKey] ?? null;
            if (! is_array($snapshot) || ! is_string($snapshot['asset_id'] ?? null)) {
                continue;
            }

            $assetId = trim($snapshot['asset_id']);
            $selection = $customFields[$fieldKey] ?? null;
            $label = $this->droneTypeLabel(
                $snapshot['manufacturer'] ?? null,
                $snapshot['model'] ?? null,
            );
            if (! Str::isUlid($assetId)
                || ! is_scalar($selection)
                || trim((string) $selection) !== $assetId
                || $label === null) {
                continue;
            }

            return ['asset_id' => $assetId, 'label' => $label];
        }

        return null;
    }
}
