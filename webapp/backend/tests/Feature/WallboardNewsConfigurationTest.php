<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardNewsConfigurationTest extends TestCase
{
    public function test_news_page_defaults_to_both_allowlisted_sources_and_six_items(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [[
                'id' => 'news',
                'name' => 'Drone nieuws',
                'type' => 'news',
                'duration_seconds' => 30,
                'options' => [],
            ]],
        ]);

        $this->assertSame([
            'sources' => ['ndt', 'dronewatch'],
            'custom_sources' => [],
            'max_items' => 6,
        ], $configuration['pages'][0]['options']);
    }

    public function test_custom_rss_can_be_the_only_source_and_can_be_reused_on_multiple_pages(): void
    {
        $customSource = [
            'id' => 'luchtvaartnieuws',
            'label' => 'Luchtvaartnieuws',
            'url' => 'https://news.example.org/drone/feed.xml',
        ];
        $pages = [
            $this->page([], 5, [$customSource], 'news-one'),
            $this->page([], 3, [$customSource], 'news-two'),
        ];

        $normalized = WallboardConfiguration::normalize(['pages' => $pages]);
        $this->assertSame([$customSource], $normalized['pages'][0]['options']['custom_sources']);

        $payload = ['name' => 'Nieuws', 'configuration' => ['pages' => $pages]];
        $this->assertCount(2, $this->validateRequest(new StoreWallboardRequest, $payload)['configuration']['pages']);
        $this->assertCount(2, $this->validateRequest(new StoreWallboardPlaylistRequest, $payload)['configuration']['pages']);
    }

    #[DataProvider('singleSourceProvider')]
    public function test_each_allowlisted_source_can_be_enabled_independently(string $source): void
    {
        $page = $this->page([$source], 12);
        $normalized = WallboardConfiguration::normalize(['pages' => [$page]]);

        $this->assertSame([$source], $normalized['pages'][0]['options']['sources']);
        $this->assertSame(12, $normalized['pages'][0]['options']['max_items']);

        $payload = ['name' => 'Nieuws playlist', 'configuration' => ['pages' => [$page]]];
        $this->assertSame([$source], $this->validateRequest(new StoreWallboardRequest, $payload)['configuration']['pages'][0]['options']['sources']);
        $this->assertSame([$source], $this->validateRequest(new StoreWallboardPlaylistRequest, $payload)['configuration']['pages'][0]['options']['sources']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function singleSourceProvider(): iterable
    {
        yield 'Nationaal Drone Team' => ['ndt'];
        yield 'Dronewatch' => ['dronewatch'];
    }

    #[DataProvider('invalidOptionsProvider')]
    public function test_normalization_and_requests_fail_closed_for_invalid_news_options(array $options, string $errorKey): void
    {
        $page = $this->page(['ndt'], 6);
        $page['options'] = $options;

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Ongeldige opgeslagen nieuwsconfiguratie had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        foreach ([new StoreWallboardRequest, new StoreWallboardPlaylistRequest] as $request) {
            try {
                $this->validateRequest($request, [
                    'name' => 'Nieuws',
                    'configuration' => ['pages' => [$page]],
                ]);
                $this->fail('Ongeldige nieuwsconfiguratie had niet door requestvalidatie mogen komen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidOptionsProvider(): iterable
    {
        yield 'geen actieve bron' => [[
            'sources' => [],
            'custom_sources' => [],
            'max_items' => 6,
        ], 'configuration.pages.0.options.sources'];

        yield 'onbekende bron' => [[
            'sources' => ['example'],
            'max_items' => 6,
        ], 'configuration.pages.0.options.sources.0'];

        yield 'dubbele bron' => [[
            'sources' => ['ndt', 'ndt'],
            'max_items' => 6,
        ], 'configuration.pages.0.options.sources.1'];

        yield 'maximum te laag' => [[
            'sources' => ['ndt'],
            'max_items' => 0,
        ], 'configuration.pages.0.options.max_items'];

        yield 'maximum te hoog' => [[
            'sources' => ['ndt'],
            'max_items' => 13,
        ], 'configuration.pages.0.options.max_items'];

        yield 'maximum is geen strict integer' => [[
            'sources' => ['ndt'],
            'max_items' => '6',
        ], 'configuration.pages.0.options.max_items'];

        yield 'onbekende optie' => [[
            'sources' => ['ndt'],
            'max_items' => 6,
            'url' => 'https://attacker.example/feed',
        ], 'configuration.pages.0.options'];

        yield 'gereserveerde custom bron-ID' => [[
            'sources' => [],
            'custom_sources' => [[
                'id' => 'ndt',
                'label' => 'Niet NDT',
                'url' => 'https://news.example.org/feed.xml',
            ]],
            'max_items' => 6,
        ], 'configuration.pages.0.options.custom_sources.0.id'];

        yield 'custom bron gebruikt onveilig http' => [[
            'sources' => [],
            'custom_sources' => [[
                'id' => 'eigen',
                'label' => 'Eigen nieuws',
                'url' => 'http://news.example.org/feed.xml',
            ]],
            'max_items' => 6,
        ], 'configuration.pages.0.options.custom_sources.0.url'];

        yield 'custom bronlabel bevat HTML' => [[
            'sources' => [],
            'custom_sources' => [[
                'id' => 'eigen',
                'label' => '<strong>Eigen nieuws</strong>',
                'url' => 'https://news.example.org/feed.xml',
            ]],
            'max_items' => 6,
        ], 'configuration.pages.0.options.custom_sources.0.label'];
    }

    public function test_update_request_accepts_news_options_and_requires_existing_version_contract(): void
    {
        $validated = $this->validateRequest(new UpdateWallboardRequest, [
            'expected_config_version' => 3,
            'configuration' => ['pages' => [$this->page(['dronewatch'], 4)]],
        ]);

        $this->assertSame(4, $validated['configuration']['pages'][0]['options']['max_items']);
    }

    /** @return array<string, mixed> */
    private function page(array $sources, int $maximumItems, array $customSources = [], string $id = 'news'): array
    {
        return [
            'id' => $id,
            'name' => 'Drone nieuws',
            'type' => 'news',
            'duration_seconds' => 30,
            'options' => [
                'sources' => $sources,
                'custom_sources' => $customSources,
                'max_items' => $maximumItems,
            ],
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
