<?php

namespace App\Support;

final class WallboardKpiDefinition
{
    public const MAX_CHARTS = 6;

    /** @var list<string> */
    public const KEYS = [
        'pilots_available',
        'pilots_unavailable',
        'pilots_total',
        'pilot_availability_rate',
        'pilots_en_route',
        'pilots_on_scene',
        'pilots_push_disabled',
        'deployments_total',
        'deployments_registered_total',
        'deployments_active',
        'deployments_dispatching',
        'deployments_in_progress',
        'deployments_low',
        'deployments_normal',
        'deployments_high',
        'deployments_critical',
        'deployments_opened_today',
        'deployments_resolved_today',
        'deployments_cancelled_today',
        'deployments_resolved_total',
        'deployments_cancelled_total',
        'assets_total',
        'assets_ready',
        'assets_maintenance',
        'assets_unavailable',
        'assets_issues',
        'drones_total',
        'drones_ready',
        'responses_targeted',
        'responses_contacted',
        'responses_pending',
        'responses_accepted',
        'responses_declined',
        'responses_no_response',
        'dispatches_active',
        'dispatch_acceptance_rate',
        'flight_reports_this_month',
        'flight_minutes_this_month',
        'average_flight_minutes_this_month',
        'drones_flown_distribution',
        'deployments_by_province',
        'deployments_by_country',
    ];

    /** @var list<string> */
    public const VISUALIZATIONS = ['counter', 'bar', 'pie', 'ring'];

    /** @var list<string> */
    private const DISTRIBUTION_KEYS = [
        'drones_flown_distribution',
        'deployments_by_province',
        'deployments_by_country',
    ];

    /** @var list<string> */
    private const RATIO_KEYS = [
        'pilot_availability_rate',
        'dispatch_acceptance_rate',
        'pilots_available',
        'pilots_unavailable',
        'pilots_en_route',
        'pilots_on_scene',
        'pilots_push_disabled',
        'deployments_active',
        'deployments_dispatching',
        'deployments_in_progress',
        'deployments_low',
        'deployments_normal',
        'deployments_high',
        'deployments_critical',
        'deployments_resolved_total',
        'deployments_cancelled_total',
        'assets_ready',
        'assets_maintenance',
        'assets_unavailable',
        'assets_issues',
        'drones_ready',
        'responses_contacted',
        'responses_pending',
        'responses_accepted',
        'responses_declined',
        'responses_no_response',
    ];

    /**
     * @return array<string, array{label: string, unit: string|null, category: string, default_visualization: string, supported_visualizations: list<string>}>
     */
    public static function definitions(): array
    {
        $base = [
            'pilots_available' => ['label' => 'Beschikbare piloten', 'unit' => null, 'category' => 'pilots'],
            'pilots_unavailable' => ['label' => 'Niet-beschikbare piloten', 'unit' => null, 'category' => 'pilots'],
            'pilots_total' => ['label' => 'Totaal piloten', 'unit' => null, 'category' => 'pilots'],
            'pilot_availability_rate' => ['label' => 'Beschikbaarheid', 'unit' => '%', 'category' => 'pilots'],
            'pilots_en_route' => ['label' => 'Piloten onderweg', 'unit' => null, 'category' => 'pilots'],
            'pilots_on_scene' => ['label' => 'Piloten op locatie', 'unit' => null, 'category' => 'pilots'],
            'pilots_push_disabled' => ['label' => 'Push uitgeschakeld', 'unit' => null, 'category' => 'pilots'],
            'deployments_total' => ['label' => 'Open inzetten totaal', 'unit' => null, 'category' => 'deployments'],
            'deployments_registered_total' => ['label' => 'Inzetten sinds registratie', 'unit' => null, 'category' => 'deployments'],
            'deployments_active' => ['label' => 'Status actief', 'unit' => null, 'category' => 'deployments'],
            'deployments_dispatching' => ['label' => 'Wordt gealarmeerd', 'unit' => null, 'category' => 'deployments'],
            'deployments_in_progress' => ['label' => 'In uitvoering', 'unit' => null, 'category' => 'deployments'],
            'deployments_low' => ['label' => 'Prioriteit laag', 'unit' => null, 'category' => 'deployments'],
            'deployments_normal' => ['label' => 'Prioriteit normaal', 'unit' => null, 'category' => 'deployments'],
            'deployments_high' => ['label' => 'Prioriteit hoog', 'unit' => null, 'category' => 'deployments'],
            'deployments_critical' => ['label' => 'Prioriteit kritiek', 'unit' => null, 'category' => 'deployments'],
            'deployments_opened_today' => ['label' => 'Inzetten geopend vandaag', 'unit' => null, 'category' => 'deployments'],
            'deployments_resolved_today' => ['label' => 'Inzetten afgerond vandaag', 'unit' => null, 'category' => 'deployments'],
            'deployments_cancelled_today' => ['label' => 'Inzetten geannuleerd vandaag', 'unit' => null, 'category' => 'deployments'],
            'deployments_resolved_total' => ['label' => 'Afgerond sinds registratie', 'unit' => null, 'category' => 'deployments'],
            'deployments_cancelled_total' => ['label' => 'Geannuleerd sinds registratie', 'unit' => null, 'category' => 'deployments'],
            'assets_total' => ['label' => 'Totaal middelen', 'unit' => null, 'category' => 'assets'],
            'assets_ready' => ['label' => 'Middelen gereed', 'unit' => null, 'category' => 'assets'],
            'assets_maintenance' => ['label' => 'Middelen in onderhoud', 'unit' => null, 'category' => 'assets'],
            'assets_unavailable' => ['label' => 'Middelen niet beschikbaar', 'unit' => null, 'category' => 'assets'],
            'assets_issues' => ['label' => 'Middelproblemen', 'unit' => null, 'category' => 'assets'],
            'drones_total' => ['label' => 'Drones totaal', 'unit' => null, 'category' => 'assets'],
            'drones_ready' => ['label' => 'Drones gereed', 'unit' => null, 'category' => 'assets'],
            'responses_targeted' => ['label' => 'Doelgroep', 'unit' => null, 'category' => 'responses'],
            'responses_contacted' => ['label' => 'Gecontacteerd', 'unit' => null, 'category' => 'responses'],
            'responses_pending' => ['label' => 'Wacht op reactie', 'unit' => null, 'category' => 'responses'],
            'responses_accepted' => ['label' => 'Komt / beschikbaar', 'unit' => null, 'category' => 'responses'],
            'responses_declined' => ['label' => 'Komt niet', 'unit' => null, 'category' => 'responses'],
            'responses_no_response' => ['label' => 'Geen reactie', 'unit' => null, 'category' => 'responses'],
            'dispatches_active' => ['label' => 'Actieve dispatches', 'unit' => null, 'category' => 'responses'],
            'dispatch_acceptance_rate' => ['label' => 'Acceptatiegraad', 'unit' => '%', 'category' => 'responses'],
            'flight_reports_this_month' => ['label' => 'Vluchten deze maand', 'unit' => null, 'category' => 'flight'],
            'flight_minutes_this_month' => ['label' => 'Vluchttijd deze maand', 'unit' => 'min', 'category' => 'flight'],
            'average_flight_minutes_this_month' => ['label' => 'Gem. vluchttijd deze maand', 'unit' => 'min', 'category' => 'flight'],
            'drones_flown_distribution' => ['label' => 'Dronetypen in inzetrapporten', 'unit' => null, 'category' => 'flight'],
            'deployments_by_province' => ['label' => 'Inzetten per provincie', 'unit' => null, 'category' => 'deployments'],
            'deployments_by_country' => ['label' => 'Inzetten per land', 'unit' => null, 'category' => 'deployments'],
        ];

        $definitions = [];
        foreach (self::KEYS as $key) {
            $definitions[$key] = $base[$key] + [
                'default_visualization' => self::defaultVisualization($key),
                'supported_visualizations' => self::supportedVisualizations($key),
            ];
        }

        return $definitions;
    }

    /** @return list<string> */
    public static function supportedVisualizations(string $key): array
    {
        return in_array($key, self::DISTRIBUTION_KEYS, true) || in_array($key, self::RATIO_KEYS, true)
            ? self::VISUALIZATIONS
            : ['counter'];
    }

    public static function defaultVisualization(string $key): string
    {
        return match ($key) {
            'drones_flown_distribution' => 'pie',
            'deployments_by_province', 'deployments_by_country' => 'bar',
            default => 'counter',
        };
    }

    /** @return array<string, string> */
    public static function defaultVisualizations(): array
    {
        $defaults = [];
        foreach (self::KEYS as $key) {
            $defaults[$key] = self::defaultVisualization($key);
        }

        return $defaults;
    }
}
