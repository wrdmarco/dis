<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardTickerConfigurationTest extends TestCase
{
    public function test_defaults_disable_an_empty_ticker(): void
    {
        $this->assertSame([
            'enabled' => false,
            'sources' => [],
        ], WallboardConfiguration::defaults()['ticker']);
    }

    public function test_normalization_replaces_the_source_list_and_trims_values(): void
    {
        $base = WallboardConfiguration::defaults();
        $base['ticker'] = [
            'enabled' => true,
            'sources' => [
                ['id' => 'old-one', 'type' => 'internal', 'label' => 'Oud', 'text' => 'Eerste'],
                ['id' => 'old-two', 'type' => 'internal', 'label' => 'Oud', 'text' => 'Tweede'],
            ],
        ];

        $normalized = WallboardConfiguration::normalize([
            'ticker' => [
                'enabled' => true,
                'sources' => [[
                    'id' => 'weather',
                    'type' => 'rss',
                    'label' => '  Weer  ',
                    'url' => '  https://weather.example.org/rss.xml  ',
                ]],
            ],
        ], $base);

        $this->assertSame([
            'enabled' => true,
            'sources' => [[
                'id' => 'weather',
                'type' => 'rss',
                'label' => 'Weer',
                'url' => 'https://weather.example.org/rss.xml',
                'max_items' => WallboardConfiguration::DEFAULT_TICKER_RSS_MAX_ITEMS,
            ]],
        ], $normalized['ticker']);
    }

    public function test_store_and_update_requests_accept_internal_and_weather_rss_sources(): void
    {
        $configuration = [
            'ticker' => [
                'enabled' => true,
                'sources' => [
                    [
                        'id' => 'internal-message',
                        'type' => 'internal',
                        'label' => 'Meldkamer',
                        'text' => 'Oefening om 19:30 uur.',
                    ],
                    [
                        'id' => 'weather',
                        'type' => 'rss',
                        'label' => 'Weer',
                        'url' => 'https://weather.example.org/rss.xml?region=west',
                        'max_items' => 3,
                    ],
                ],
            ],
        ];

        $store = $this->validateRequest(new StoreWallboardRequest, [
            'name' => 'Operationeel scherm',
            'configuration' => $configuration,
        ]);
        $update = $this->validateRequest(new UpdateWallboardRequest, [
            'expected_config_version' => 1,
            'configuration' => $configuration,
        ]);

        $this->assertSame($configuration['ticker'], $store['configuration']['ticker']);
        $this->assertSame($configuration['ticker'], $update['configuration']['ticker']);
    }

    public function test_every_wallboard_request_contract_accepts_rss_item_limit_boundaries(): void
    {
        foreach ([
            [new StoreWallboardRequest, ['name' => 'Scherm']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Playlist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ] as [$request, $basePayload]) {
            foreach ([
                WallboardConfiguration::MIN_TICKER_RSS_MAX_ITEMS,
                WallboardConfiguration::MAX_TICKER_RSS_MAX_ITEMS,
            ] as $maximumItems) {
                $payload = [
                    ...$basePayload,
                    'configuration' => [
                        'ticker' => [
                            'enabled' => true,
                            'sources' => [[
                                'id' => 'weather',
                                'type' => 'rss',
                                'label' => 'Weer',
                                'url' => 'https://weather.example.org/rss.xml',
                                'max_items' => $maximumItems,
                            ]],
                        ],
                    ],
                ];

                $validated = $this->validateRequest($request, $payload);

                $this->assertSame(
                    $maximumItems,
                    $validated['configuration']['ticker']['sources'][0]['max_items'],
                );
            }
        }
    }

    #[DataProvider('invalidMaxItemsProvider')]
    public function test_every_wallboard_request_contract_rejects_non_strict_or_out_of_range_rss_item_limits(mixed $maximumItems): void
    {
        foreach ([
            [new StoreWallboardRequest, ['name' => 'Scherm']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Playlist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ] as [$request, $basePayload]) {
            try {
                $this->validateRequest($request, [
                    ...$basePayload,
                    'configuration' => [
                        'ticker' => [
                            'enabled' => true,
                            'sources' => [[
                                'id' => 'weather',
                                'type' => 'rss',
                                'label' => 'Weer',
                                'url' => 'https://weather.example.org/rss.xml',
                                'max_items' => $maximumItems,
                            ]],
                        ],
                    ],
                ]);
                $this->fail('Een ongeldige RSS-itemlimiet had niet gevalideerd mogen worden.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('configuration.ticker.sources.0.max_items', $exception->errors());
            }
        }
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidMaxItemsProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'above maximum' => [9];
        yield 'numeric string' => ['3'];
        yield 'float' => [3.0];
        yield 'boolean' => [true];
        yield 'null' => [null];
    }

    #[DataProvider('invalidSourceProvider')]
    public function test_requests_reject_unknown_type_incompatible_and_unsafe_source_fields(array $source, string $errorKey): void
    {
        foreach ([new StoreWallboardRequest, new UpdateWallboardRequest] as $request) {
            $payload = [
                'name' => 'Ticker scherm',
                'expected_config_version' => 1,
                'configuration' => [
                    'ticker' => [
                        'enabled' => true,
                        'sources' => [$source],
                    ],
                ],
            ];

            try {
                $this->validateRequest($request, $payload);
                $this->fail('Tickerbron had niet gevalideerd mogen worden.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidSourceProvider(): iterable
    {
        yield 'unknown key' => [[
            'id' => 'news',
            'type' => 'rss',
            'label' => 'Nieuws',
            'url' => 'https://feeds.example.org/rss.xml',
            'script' => 'alert(1)',
        ], 'configuration.ticker.sources.0'];

        yield 'rss with internal text' => [[
            'id' => 'news',
            'type' => 'rss',
            'label' => 'Nieuws',
            'url' => 'https://feeds.example.org/rss.xml',
            'text' => 'Niet toegestaan',
        ], 'configuration.ticker.sources.0'];

        yield 'internal with url' => [[
            'id' => 'message',
            'type' => 'internal',
            'label' => 'Intern',
            'text' => 'Bericht',
            'url' => 'https://feeds.example.org/rss.xml',
        ], 'configuration.ticker.sources.0'];

        yield 'internal with RSS item limit' => [[
            'id' => 'message',
            'type' => 'internal',
            'label' => 'Intern',
            'text' => 'Bericht',
            'max_items' => 2,
        ], 'configuration.ticker.sources.0'];

        yield 'http feed' => [[
            'id' => 'news',
            'type' => 'rss',
            'label' => 'Nieuws',
            'url' => 'http://feeds.example.org/rss.xml',
        ], 'configuration.ticker.sources.0.url'];

        yield 'private IP feed' => [[
            'id' => 'internal-network',
            'type' => 'rss',
            'label' => 'Intern netwerk',
            'url' => 'https://127.0.0.1/rss.xml',
        ], 'configuration.ticker.sources.0.url'];

        yield 'markup in label' => [[
            'id' => 'news',
            'type' => 'rss',
            'label' => '<b>Nieuws</b>',
            'url' => 'https://feeds.example.org/rss.xml',
        ], 'configuration.ticker.sources.0.label'];

        yield 'markup in internal text' => [[
            'id' => 'message',
            'type' => 'internal',
            'label' => 'Intern',
            'text' => '<img src=x onerror=alert(1)>',
        ], 'configuration.ticker.sources.0.text'];

        yield 'invalid id' => [[
            'id' => '../news',
            'type' => 'rss',
            'label' => 'Nieuws',
            'url' => 'https://feeds.example.org/rss.xml',
        ], 'configuration.ticker.sources.0.id'];

        yield 'unsupported type' => [[
            'id' => 'weather',
            'type' => 'weather_api',
            'label' => 'Weer',
            'url' => 'https://weather.example.org/api',
        ], 'configuration.ticker.sources.0.type'];
    }

    public function test_requests_reject_duplicate_ids_more_than_ten_sources_and_unknown_ticker_keys(): void
    {
        $duplicate = ['id' => 'same', 'type' => 'internal', 'label' => 'Intern', 'text' => 'Bericht'];
        $tooMany = [];
        for ($index = 0; $index < 11; $index++) {
            $tooMany[] = [
                'id' => "source-{$index}",
                'type' => 'internal',
                'label' => "Bron {$index}",
                'text' => "Bericht {$index}",
            ];
        }

        $this->assertInvalidStoreTicker([$duplicate, $duplicate], 'configuration.ticker.sources.1.id');
        $this->assertInvalidStoreTicker($tooMany, 'configuration.ticker.sources');

        try {
            $this->validateRequest(new StoreWallboardRequest, [
                'name' => 'Ticker scherm',
                'configuration' => [
                    'ticker' => ['enabled' => true, 'sources' => [], 'speed' => 5],
                ],
            ]);
            $this->fail('Onbekende tickerinstelling had niet gevalideerd mogen worden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.ticker', $exception->errors());
        }
    }

    #[DataProvider('invalidNormalizedSourceProvider')]
    public function test_normalization_fails_closed_for_direct_or_stored_invalid_sources(array $source, string $errorKey): void
    {
        try {
            WallboardConfiguration::normalize([
                'ticker' => ['enabled' => true, 'sources' => [$source]],
            ]);
            $this->fail('Ongeldige opgeslagen tickerbron had niet genormaliseerd mogen worden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidNormalizedSourceProvider(): iterable
    {
        yield 'wrong field for internal' => [[
            'id' => 'message',
            'type' => 'internal',
            'label' => 'Intern',
            'text' => 'Bericht',
            'url' => 'https://feeds.example.org/rss.xml',
        ], 'configuration.ticker.sources.0'];

        yield 'unsafe RSS URL' => [[
            'id' => 'feed',
            'type' => 'rss',
            'label' => 'Feed',
            'url' => 'https://[::1]/feed.xml',
        ], 'configuration.ticker.sources.0.url'];

        yield 'RSS item limit below minimum' => [[
            'id' => 'feed',
            'type' => 'rss',
            'label' => 'Feed',
            'url' => 'https://feeds.example.org/rss.xml',
            'max_items' => 0,
        ], 'configuration.ticker.sources.0.max_items'];

        yield 'RSS item limit above maximum' => [[
            'id' => 'feed',
            'type' => 'rss',
            'label' => 'Feed',
            'url' => 'https://feeds.example.org/rss.xml',
            'max_items' => 9,
        ], 'configuration.ticker.sources.0.max_items'];

        yield 'RSS item limit must be a strict integer' => [[
            'id' => 'feed',
            'type' => 'rss',
            'label' => 'Feed',
            'url' => 'https://feeds.example.org/rss.xml',
            'max_items' => '3',
        ], 'configuration.ticker.sources.0.max_items'];
    }

    /** @return array<string, mixed> */
    private function validateRequest(FormRequest $request, array $payload): array
    {
        $request->initialize($payload);
        $validator = ValidatorFacade::make($request->all(), $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        return $validator->validate();
    }

    /** @param list<array<string, mixed>> $sources */
    private function assertInvalidStoreTicker(array $sources, string $errorKey): void
    {
        try {
            $this->validateRequest(new StoreWallboardRequest, [
                'name' => 'Ticker scherm',
                'configuration' => [
                    'ticker' => ['enabled' => true, 'sources' => $sources],
                ],
            ]);
            $this->fail('Ongeldige tickerconfiguratie had niet gevalideerd mogen worden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }
}
