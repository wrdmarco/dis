<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Support\WallboardConfiguration;
use App\Support\WallboardKpiDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardKpiConfigurationTest extends TestCase
{
    public function test_legacy_kpi_options_receive_the_complete_canonical_visualization_map(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->kpiPage([])],
        ]);
        $options = $configuration['pages'][0]['options'];

        $this->assertCount(42, WallboardKpiDefinition::KEYS);
        $this->assertSame(WallboardKpiDefinition::KEYS, WallboardConfiguration::KPI_VISIBLE_METRICS);
        $this->assertSame(WallboardKpiDefinition::KEYS, $options['visible_metrics']);
        $this->assertSame(WallboardKpiDefinition::defaultVisualizations(), $options['metric_visualizations']);
        $this->assertSame('pie', $options['metric_visualizations']['drones_flown_distribution']);
        $this->assertSame('bar', $options['metric_visualizations']['incidents_by_province']);
        $this->assertSame('bar', $options['metric_visualizations']['incidents_by_country']);
    }

    public function test_six_visible_charts_are_allowed_and_hidden_chart_preferences_do_not_count(): void
    {
        $visible = [
            'pilots_available',
            'pilot_availability_rate',
            'assets_ready',
            'responses_accepted',
            'drones_flown_distribution',
            'incidents_by_province',
            'incidents_registered_total',
        ];
        $visualizations = [
            'pilots_available' => 'ring',
            'pilot_availability_rate' => 'pie',
            'assets_ready' => 'bar',
            'responses_accepted' => 'ring',
            'drones_flown_distribution' => 'pie',
            'incidents_by_province' => 'bar',
            'incidents_dispatching' => 'pie',
        ];
        $page = $this->kpiPage([
            'visible_metrics' => array_reverse($visible),
            'metric_visualizations' => $visualizations,
        ]);

        $configuration = WallboardConfiguration::normalize(['pages' => [$page]]);
        $options = $configuration['pages'][0]['options'];
        $this->assertSame(
            array_values(array_filter(
                WallboardKpiDefinition::KEYS,
                static fn (string $key): bool => in_array($key, $visible, true),
            )),
            $options['visible_metrics'],
        );
        $this->assertSame('pie', $options['metric_visualizations']['incidents_dispatching']);

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$page]],
            ]);
            $this->assertSame($visualizations, $validated['configuration']['pages'][0]['options']['metric_visualizations']);
        }
    }

    #[DataProvider('invalidVisualizationOptionsProvider')]
    public function test_visualization_options_fail_closed_in_normalization_and_every_admin_contract(
        array $options,
        string $errorKey,
    ): void {
        $page = $this->kpiPage($options);

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Ongeldige KPI-weergaven hadden niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            try {
                $this->validateRequest($request, [
                    ...$basePayload,
                    'configuration' => ['pages' => [$page]],
                ]);
                $this->fail('Ongeldige KPI-weergaven hadden niet door requestvalidatie mogen komen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidVisualizationOptionsProvider(): iterable
    {
        yield 'los totaal als ring' => [[
            'visible_metrics' => ['pilots_total'],
            'metric_visualizations' => ['pilots_total' => 'ring'],
        ], 'configuration.pages.0.options.metric_visualizations.pilots_total'];

        yield 'onbekende visualisatie' => [[
            'visible_metrics' => ['pilots_available'],
            'metric_visualizations' => ['pilots_available' => 'meter'],
        ], 'configuration.pages.0.options.metric_visualizations.pilots_available'];

        yield 'onbekende KPI-sleutel' => [[
            'visible_metrics' => ['pilots_available'],
            'metric_visualizations' => ['geheime_kpi' => 'ring'],
        ], 'configuration.pages.0.options.metric_visualizations'];

        yield 'lijst in plaats van sleutel-object' => [[
            'visible_metrics' => ['pilots_available'],
            'metric_visualizations' => ['ring'],
        ], 'configuration.pages.0.options.metric_visualizations'];

        $sevenCharts = [
            'pilots_available',
            'pilots_unavailable',
            'pilot_availability_rate',
            'pilots_en_route',
            'pilots_on_scene',
            'pilots_push_disabled',
            'incidents_active',
        ];
        yield 'meer dan zes zichtbare diagrammen' => [[
            'visible_metrics' => $sevenCharts,
            'metric_visualizations' => array_fill_keys($sevenCharts, 'ring'),
        ], 'configuration.pages.0.options.metric_visualizations'];
    }

    /** @return list<array{0: FormRequest, 1: array<string, int|string>}> */
    private function requestContracts(): array
    {
        return [
            [new StoreWallboardRequest, ['name' => 'KPI-wallboard']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'KPI-playlist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ];
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function kpiPage(array $options): array
    {
        return [
            'id' => 'kpi-main',
            'name' => 'KPI-overzicht',
            'type' => 'kpi',
            'duration_seconds' => 30,
            'options' => $options,
        ];
    }

    /** @return array<string, mixed> */
    private function validateRequest(FormRequest $request, array $payload): array
    {
        $request->initialize($payload);
        $validator = Validator::make($request->all(), $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        return $validator->validate();
    }
}
