<?php

namespace App\Services;

use App\Models\DispatchRecipient;
use App\Models\User;
use App\Repositories\WallboardKpiRepository;
use App\Support\ApiDateTime;
use App\Support\WallboardConfiguration;
use App\Support\WallboardKpiDefinition;
use Carbon\CarbonImmutable;

final class WallboardKpiService
{
    private const BUSINESS_TIMEZONE = 'Europe/Amsterdam';

    /** @var array<int, string> */
    private const DUTCH_MONTHS = [
        1 => 'januari',
        2 => 'februari',
        3 => 'maart',
        4 => 'april',
        5 => 'mei',
        6 => 'juni',
        7 => 'juli',
        8 => 'augustus',
        9 => 'september',
        10 => 'oktober',
        11 => 'november',
        12 => 'december',
    ];

    public function __construct(
        private readonly WallboardKpiRepository $repository,
        private readonly AvailabilityScheduleService $availabilityScheduleService,
        private readonly PilotIncidentReportFormService $pilotReportFormService,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array{available: int, total: int, en_route: int, on_scene: int, push_disabled: int}|null  $pilotMetrics
     * @return array<string, mixed>
     */
    public function pages(array $configuration, ?array $pilotMetrics = null): array
    {
        $pages = collect((array) ($configuration['pages'] ?? []))
            ->filter(static fn (mixed $page): bool => is_array($page) && ($page['type'] ?? null) === 'kpi')
            ->values();
        if ($pages->isEmpty()) {
            return ['generated_at' => ApiDateTime::now(), 'pages' => []];
        }

        $selections = $pages->mapWithKeys(fn (array $page): array => [
            (string) $page['id'] => [
                'metrics' => $this->selectedMetrics((array) ($page['options'] ?? [])),
                'visualizations' => $this->selectedVisualizations((array) ($page['options'] ?? [])),
            ],
        ]);
        $selected = $selections
            ->flatMap(static fn (array $selection): array => $selection['metrics'])
            ->unique()
            ->values()
            ->all();
        $definitions = WallboardKpiDefinition::definitions();
        $categories = array_values(array_unique(array_map(
            static fn (string $key): string => $definitions[$key]['category'],
            $selected,
        )));
        $values = array_fill_keys(WallboardConfiguration::KPI_VISIBLE_METRICS, 0);
        $segments = [];
        $contexts = [];
        $denominators = [];
        $numerators = [];
        $nowAmsterdam = CarbonImmutable::now(self::BUSINESS_TIMEZONE);

        if (in_array('pilots', $categories, true)) {
            $pilotMetrics ??= $this->pilotMetrics();
            $available = (int) $pilotMetrics['available'];
            $total = (int) $pilotMetrics['total'];
            $values['pilots_available'] = $available;
            $values['pilots_unavailable'] = max(0, $total - $available);
            $values['pilots_total'] = $total;
            $values['pilot_availability_rate'] = $total === 0
                ? null
                : round(($available / $total) * 100, 1);
            $values['pilots_en_route'] = (int) $pilotMetrics['en_route'];
            $values['pilots_on_scene'] = (int) $pilotMetrics['on_scene'];
            $values['pilots_push_disabled'] = (int) $pilotMetrics['push_disabled'];
            foreach ([
                'pilots_available',
                'pilots_unavailable',
                'pilot_availability_rate',
                'pilots_en_route',
                'pilots_on_scene',
                'pilots_push_disabled',
            ] as $key) {
                $denominators[$key] = $total;
            }
            $numerators['pilot_availability_rate'] = $available;
        }

        if (in_array('incidents', $categories, true)) {
            $counts = $this->repository->activeIncidentCounts();
            $values['incidents_total'] = $counts['total'];
            foreach ($counts['by_status'] as $status => $count) {
                $values['incidents_'.$status] = $count;
                $denominators['incidents_'.$status] = $counts['total'];
            }
            foreach ($counts['by_priority'] as $priority => $count) {
                $values['incidents_'.$priority] = $count;
                $denominators['incidents_'.$priority] = $counts['total'];
            }
            $dayStartUtc = $nowAmsterdam->startOfDay()->setTimezone('UTC');
            $dayEndUtc = $nowAmsterdam->startOfDay()->addDay()->setTimezone('UTC');
            foreach ($this->repository->incidentLifecycleCounts($dayStartUtc, $dayEndUtc) as $key => $count) {
                $values['incidents_'.$key] = $count;
            }
            $denominators['incidents_resolved_total'] = $values['incidents_registered_total'];
            $denominators['incidents_cancelled_total'] = $values['incidents_registered_total'];

            if (in_array('incidents_by_province', $selected, true)) {
                $segments['incidents_by_province'] = $this->repository->incidentProvinceDistribution();
                $values['incidents_by_province'] = $this->segmentTotal($segments['incidents_by_province']);
                $contexts['incidents_by_province'] = 'Sinds registratie · Nederland + onbekend';
            }
            if (in_array('incidents_by_country', $selected, true)) {
                $segments['incidents_by_country'] = $this->repository->incidentCountryDistribution();
                $values['incidents_by_country'] = $this->segmentTotal($segments['incidents_by_country']);
                $contexts['incidents_by_country'] = 'Sinds registratie · onbekend apart';
            }
        }

        if (in_array('assets', $categories, true)) {
            $counts = $this->repository->assetCounts();
            $values['assets_total'] = $counts['total'];
            $values['assets_ready'] = $counts['ready'];
            $values['assets_maintenance'] = $counts['maintenance'];
            $values['assets_unavailable'] = $counts['unavailable'];
            $values['assets_issues'] = $counts['issues'];
            $values['drones_total'] = $counts['drones_total'];
            $values['drones_ready'] = $counts['drones_ready'];
            foreach (['assets_ready', 'assets_maintenance', 'assets_unavailable', 'assets_issues'] as $key) {
                $denominators[$key] = $counts['total'];
            }
            $denominators['drones_ready'] = $counts['drones_total'];
        }

        if (in_array('responses', $categories, true)) {
            $counts = $this->responseCounts();
            foreach ($counts as $key => $count) {
                $values['responses_'.$key] = $count;
            }
            $values['dispatches_active'] = $this->repository->activeDispatchCount();
            $values['dispatch_acceptance_rate'] = $counts['targeted'] === 0
                ? null
                : round(($counts['accepted'] / $counts['targeted']) * 100, 1);
            foreach ([
                'responses_contacted',
                'responses_pending',
                'responses_accepted',
                'responses_declined',
                'responses_no_response',
                'dispatch_acceptance_rate',
            ] as $key) {
                $denominators[$key] = $counts['targeted'];
            }
            $numerators['dispatch_acceptance_rate'] = $counts['accepted'];
        }

        if (in_array('flight', $categories, true)) {
            $monthStartUtc = $nowAmsterdam->startOfMonth()->setTimezone('UTC');
            $monthEndUtc = $nowAmsterdam->startOfMonth()->addMonth()->setTimezone('UTC');
            $flight = $this->repository->flightMetrics($monthStartUtc, $monthEndUtc);
            $values['flight_reports_this_month'] = $flight['reports'];
            $values['flight_minutes_this_month'] = $flight['minutes'];
            $values['average_flight_minutes_this_month'] = $flight['average_minutes'];
            $monthLabel = self::DUTCH_MONTHS[$nowAmsterdam->month].' '.$nowAmsterdam->year;
            $monthContext = $monthLabel.' · ingediend · >0 vliegminuten';
            $contexts['flight_reports_this_month'] = $monthContext;
            $contexts['flight_minutes_this_month'] = $monthContext;
            $contexts['average_flight_minutes_this_month'] = $monthContext;

            if (in_array('drones_flown_distribution', $selected, true)) {
                $segments['drones_flown_distribution'] = $this->repository->droneFlightDistribution(
                    $monthStartUtc,
                    $monthEndUtc,
                    $this->pilotReportFormService->droneFieldKeys(),
                );
                $values['drones_flown_distribution'] = $flight['reports'];
                $contexts['drones_flown_distribution'] = $monthLabel.' · 1 type per rapport · onbekend apart';
            }
        }

        return [
            'generated_at' => ApiDateTime::now(),
            'pages' => $selections->map(function (array $selection) use (
                $contexts,
                $definitions,
                $denominators,
                $numerators,
                $segments,
                $values,
            ): array {
                return [
                    'metrics' => array_map(function (string $key) use (
                        $contexts,
                        $definitions,
                        $denominators,
                        $numerators,
                        $segments,
                        $selection,
                        $values,
                    ): array {
                        $visualization = $selection['visualizations'][$key];
                        $metric = [
                            'key' => $key,
                            'label' => $definitions[$key]['label'],
                            'value' => $values[$key],
                            'unit' => $definitions[$key]['unit'],
                            'category' => $definitions[$key]['category'],
                            'visualization' => $visualization,
                        ];
                        if (isset($contexts[$key])) {
                            $metric['context'] = $contexts[$key];
                        }
                        if ($visualization !== 'counter') {
                            $metric['segments'] = $segments[$key]
                                ?? $this->ratioSegments(
                                    $key,
                                    (int) ($numerators[$key] ?? $values[$key] ?? 0),
                                    (int) ($denominators[$key] ?? 0),
                                    $definitions[$key]['label'],
                                );
                        }

                        return $metric;
                    }, $selection['metrics']),
                ];
            })->all(),
        ];
    }

    /** @return array{available: int, total: int} */
    public function pilotAvailability(): array
    {
        $metrics = $this->pilotMetrics();

        return ['available' => $metrics['available'], 'total' => $metrics['total']];
    }

    /** @return array{available: int, total: int, en_route: int, on_scene: int, push_disabled: int} */
    public function pilotMetrics(): array
    {
        $pilots = $this->repository->pilots();
        $scheduledAvailability = $this->availabilityScheduleService->availabilityByUser($pilots);
        $available = $pilots->filter(function (User $pilot) use ($scheduledAvailability): bool {
            $latestStatus = $pilot->statuses->first();

            return (bool) $pilot->push_enabled
                && ($latestStatus === null || (bool) $latestStatus->is_available)
                && ($scheduledAvailability[(string) $pilot->id] ?? true);
        })->count();

        $latestStatuses = $pilots->map(
            static fn (User $pilot): ?string => $pilot->statuses->first()?->status,
        );

        return [
            'available' => $available,
            'total' => $pilots->count(),
            'en_route' => $latestStatuses->filter(static fn (?string $status): bool => $status === 'en_route')->count(),
            'on_scene' => $latestStatuses->filter(static fn (?string $status): bool => $status === 'on_scene')->count(),
            'push_disabled' => $pilots->filter(static fn (User $pilot): bool => ! (bool) $pilot->push_enabled)->count(),
        ];
    }

    /**
     * @return array{targeted: int, contacted: int, pending: int, accepted: int, declined: int, no_response: int}
     */
    private function responseCounts(): array
    {
        $recipients = $this->repository->activeResponseRecipients()
            ->unique(static function (DispatchRecipient $recipient): string {
                $incidentId = (string) $recipient->dispatchRequest?->incident_id;

                return $incidentId.'|'.($recipient->user_id === null
                    ? 'deleted:'.(string) $recipient->id
                    : 'user:'.(string) $recipient->user_id);
            })
            ->values();
        $byStatus = $recipients->countBy(
            static fn (DispatchRecipient $recipient): string => (string) $recipient->response_status,
        );

        return [
            'targeted' => $recipients->count(),
            'contacted' => $recipients->whereNotNull('notified_at')->count(),
            'pending' => (int) $byStatus->get('pending', 0),
            'accepted' => (int) $byStatus->get('accepted', 0),
            'declined' => (int) $byStatus->get('declined', 0),
            'no_response' => (int) $byStatus->get('no_response', 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function selectedMetrics(array $options): array
    {
        $selected = array_key_exists('visible_metrics', $options)
            && is_array($options['visible_metrics'])
                ? $options['visible_metrics']
                : WallboardConfiguration::KPI_VISIBLE_METRICS;

        return array_values(array_filter(
            WallboardConfiguration::KPI_VISIBLE_METRICS,
            static fn (string $key): bool => in_array($key, $selected, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function selectedVisualizations(array $options): array
    {
        $configured = is_array($options['metric_visualizations'] ?? null)
            ? $options['metric_visualizations']
            : [];
        $visualizations = [];
        foreach (WallboardConfiguration::KPI_VISIBLE_METRICS as $key) {
            $candidate = $configured[$key] ?? WallboardKpiDefinition::defaultVisualization($key);
            $visualizations[$key] = is_string($candidate)
                && in_array($candidate, WallboardKpiDefinition::supportedVisualizations($key), true)
                    ? $candidate
                    : WallboardKpiDefinition::defaultVisualization($key);
        }

        return $visualizations;
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function ratioSegments(string $key, int $part, int $denominator, string $label): array
    {
        $part = max(0, min($part, $denominator));
        [$partLabel, $remainderLabel] = match ($key) {
            'pilot_availability_rate' => ['Beschikbaar', 'Niet beschikbaar'],
            'dispatch_acceptance_rate' => ['Geaccepteerd', 'Niet geaccepteerd'],
            default => [$label, 'Overig'],
        };

        return [
            ['label' => $partLabel, 'value' => $part],
            ['label' => $remainderLabel, 'value' => max(0, $denominator - $part)],
        ];
    }

    /** @param list<array{label: string, value: int}> $segments */
    private function segmentTotal(array $segments): int
    {
        return array_sum(array_column($segments, 'value'));
    }
}
