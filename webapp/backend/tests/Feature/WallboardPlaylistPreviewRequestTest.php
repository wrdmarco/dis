<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\PreviewWallboardPlaylistRequest;
use App\Models\WallboardPlaylist;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

final class WallboardPlaylistPreviewRequestTest extends TestCase
{
    public function test_demo_preview_fills_only_empty_dynamic_inputs_before_validation(): void
    {
        $validated = $this->validateRequest([
            'data_mode' => WallboardPlaylist::DATA_MODE_DEMO,
            'configuration' => [
                'pages' => [
                    $this->page('quote', 'quote', ['quotes' => [['text' => '', 'author' => '']]]),
                    $this->page('forecast', 'uav_forecast', [
                        'location_mode' => 'address',
                        'location_label' => '',
                    ]),
                    $this->page('news', 'news', [
                        'sources' => [],
                        'custom_sources' => [],
                    ]),
                    $this->page('calendar', 'calendar'),
                    $this->page('kpi', 'kpi'),
                    $this->page('map', 'map'),
                    $this->page('incidents', 'incident_list'),
                    $this->page('summary', 'summary'),
                ],
                'ticker' => [
                    'enabled' => true,
                    'sources' => [[
                        'id' => 'unfinished-demo-ticker',
                        'type' => 'rss',
                        'label' => 'Nieuws- of weer-RSS',
                        'url' => '',
                    ]],
                ],
            ],
        ]);

        $this->assertSame(
            'Goede voorbereiding geeft elke vlucht een veilige start.',
            $validated['configuration']['pages'][0]['options']['quotes'][0]['text'],
        );
        $this->assertSame('DIS DEMO', $validated['configuration']['pages'][0]['options']['quotes'][0]['author']);
        $this->assertSame(
            'Demolocatie (fictief)',
            $validated['configuration']['pages'][1]['options']['location_label'],
        );
        $this->assertSame(['ndt'], $validated['configuration']['pages'][2]['options']['sources']);
        $this->assertSame([], $validated['configuration']['ticker']['sources']);
    }

    #[DataProvider('emptyDynamicInputProvider')]
    public function test_live_preview_keeps_rejecting_empty_dynamic_inputs(array $configuration, string $errorField): void
    {
        try {
            $this->validateRequest([
                'data_mode' => WallboardPlaylist::DATA_MODE_LIVE,
                'configuration' => $configuration,
            ]);
            $this->fail('Lege dynamische live-invoer had niet door previewvalidatie mogen komen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorField, $exception->errors());
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function emptyDynamicInputProvider(): iterable
    {
        yield 'quote' => [
            ['pages' => [self::page('quote', 'quote', ['quotes' => []])]],
            'configuration.pages.0.options.quotes',
        ];
        yield 'forecastadres' => [
            ['pages' => [self::page('forecast', 'uav_forecast', [
                'location_mode' => 'address',
                'location_label' => '',
            ])]],
            'configuration.pages.0.options.location_label',
        ];
        yield 'nieuwsbronnen' => [
            ['pages' => [self::page('news', 'news', [
                'sources' => [],
                'custom_sources' => [],
            ])]],
            'configuration.pages.0.options.sources',
        ];
        yield 'tickerbron' => [
            [
                'pages' => [self::page('summary', 'summary')],
                'ticker' => [
                    'enabled' => true,
                    'sources' => [[
                        'id' => 'unfinished-live-ticker',
                        'type' => 'internal',
                        'label' => 'Intern bericht',
                        'text' => '',
                    ]],
                ],
            ],
            'configuration.ticker.sources.0.text',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function page(string $id, string $type, array $options = []): array
    {
        return [
            'id' => $id,
            'name' => $id,
            'type' => $type,
            'duration_seconds' => 30,
            'options' => $options,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateRequest(array $payload): array
    {
        $request = new PreviewWallboardPlaylistRequest;
        $request->initialize($payload);
        (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

        $validator = Validator::make($request->all(), $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        return $validator->validate();
    }
}
