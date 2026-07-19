<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardTransitionConfigurationTest extends TestCase
{
    public function test_transition_defaults_are_backward_compatible(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->newsPage()],
        ]);

        $this->assertTrue($configuration['page_fade_enabled']);
        $this->assertSame('fade', $configuration['page_transition']);
        $this->assertSame(320, $configuration['page_transition_duration_ms']);
        $this->assertSame('left_to_right', $configuration['page_flip_direction']);
        $this->assertSame('fade', $configuration['pages'][0]['options']['item_transition']);
        $this->assertSame(720, $configuration['pages'][0]['options']['item_transition_duration_ms']);
        $this->assertSame('left_to_right', $configuration['pages'][0]['options']['item_flip_direction']);
    }

    public function test_legacy_page_fade_is_migrated_without_changing_old_client_behaviour(): void
    {
        $disabled = WallboardConfiguration::normalize([
            'page_fade_enabled' => false,
            'pages' => [$this->newsPage()],
        ]);
        $enabled = WallboardConfiguration::normalize([
            'page_fade_enabled' => true,
            'pages' => [$this->newsPage()],
        ]);

        $this->assertSame('none', $disabled['page_transition']);
        $this->assertFalse($disabled['page_fade_enabled']);
        $this->assertSame('fade', $enabled['page_transition']);
        $this->assertTrue($enabled['page_fade_enabled']);
    }

    public function test_legacy_client_write_preserves_a_richer_stored_transition_until_toggle_changes(): void
    {
        $base = WallboardConfiguration::normalize([
            'page_transition' => 'flip',
            'page_transition_duration_ms' => 1200,
            'page_flip_direction' => 'bottom_to_top',
            'pages' => [$this->newsPage()],
        ]);

        $unchanged = WallboardConfiguration::normalize([
            'page_fade_enabled' => true,
            'pages' => [$this->newsPage()],
        ], $base);
        $disabled = WallboardConfiguration::normalize([
            'page_fade_enabled' => false,
            'pages' => [$this->newsPage()],
        ], $base);
        $newContractWins = WallboardConfiguration::normalize([
            'page_fade_enabled' => false,
            'page_transition' => 'slide',
            'pages' => [$this->newsPage()],
        ], $base);

        $this->assertSame('flip', $unchanged['page_transition']);
        $this->assertSame(1200, $unchanged['page_transition_duration_ms']);
        $this->assertSame('bottom_to_top', $unchanged['page_flip_direction']);
        $this->assertSame('none', $disabled['page_transition']);
        $this->assertFalse($disabled['page_fade_enabled']);
        $this->assertSame('slide', $newContractWins['page_transition']);
        $this->assertTrue($newContractWins['page_fade_enabled']);
    }

    #[DataProvider('pageTransitionProvider')]
    public function test_global_and_page_transition_overrides_are_normalized_and_bounded(string $transition): void
    {
        $page = $this->newsPage('fade');
        $page['transition'] = $transition;
        $page['transition_duration_ms'] = 1400;
        $page['options']['item_transition_duration_ms'] = 1800;

        $configuration = WallboardConfiguration::normalize([
            'page_transition' => $transition,
            'page_transition_duration_ms' => 900,
            'pages' => [$page],
        ]);

        $this->assertSame($transition, $configuration['page_transition']);
        $this->assertSame(900, $configuration['page_transition_duration_ms']);
        $this->assertSame($transition !== 'none', $configuration['page_fade_enabled']);
        $this->assertSame($transition, $configuration['pages'][0]['transition']);
        $this->assertSame(1400, $configuration['pages'][0]['transition_duration_ms']);
        $this->assertSame(1800, $configuration['pages'][0]['options']['item_transition_duration_ms']);

        $validated = $this->validateRequest(new StoreWallboardPlaylistRequest, [
            'name' => 'Nieuws',
            'configuration' => [
                'page_transition' => $transition,
                'page_transition_duration_ms' => 900,
                'pages' => [$page],
            ],
        ]);
        $this->assertSame($transition, $validated['configuration']['pages'][0]['transition']);
        $this->assertSame(1400, $validated['configuration']['pages'][0]['transition_duration_ms']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function pageTransitionProvider(): iterable
    {
        yield from self::newsTransitionProvider();
    }

    public function test_null_page_overrides_fall_back_to_global_settings_and_are_not_persisted(): void
    {
        $page = $this->newsPage();
        $page['transition'] = null;
        $page['transition_duration_ms'] = null;
        $page['flip_direction'] = null;

        $configuration = WallboardConfiguration::normalize([
            'page_transition' => 'slide',
            'page_transition_duration_ms' => 800,
            'page_flip_direction' => 'random',
            'pages' => [$page],
        ]);

        $this->assertArrayNotHasKey('transition', $configuration['pages'][0]);
        $this->assertArrayNotHasKey('transition_duration_ms', $configuration['pages'][0]);
        $this->assertArrayNotHasKey('flip_direction', $configuration['pages'][0]);
        $this->assertSame('random', $configuration['page_flip_direction']);
    }

    public function test_all_wallboard_write_requests_accept_the_extended_transition_contract(): void
    {
        $page = $this->newsPage('flip');
        $page['transition'] = 'slide';
        $page['transition_duration_ms'] = 650;
        $page['flip_direction'] = 'bottom_to_top';
        $page['options']['item_transition_duration_ms'] = 1100;
        $page['options']['item_flip_direction'] = 'top_to_bottom';
        $configuration = [
            'page_transition' => 'dissolve',
            'page_transition_duration_ms' => 450,
            'page_flip_direction' => 'random',
            'pages' => [$page],
        ];

        foreach ([
            [new StoreWallboardRequest, ['name' => 'Scherm', 'configuration' => $configuration]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Playlist', 'configuration' => $configuration]],
            [new UpdateWallboardRequest, ['expected_config_version' => 2, 'configuration' => $configuration]],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 2, 'configuration' => $configuration]],
        ] as [$request, $payload]) {
            $validated = $this->validateRequest($request, $payload);

            $this->assertSame('dissolve', $validated['configuration']['page_transition']);
            $this->assertSame(450, $validated['configuration']['page_transition_duration_ms']);
            $this->assertSame('random', $validated['configuration']['page_flip_direction']);
            $this->assertSame('slide', $validated['configuration']['pages'][0]['transition']);
            $this->assertSame(650, $validated['configuration']['pages'][0]['transition_duration_ms']);
            $this->assertSame('bottom_to_top', $validated['configuration']['pages'][0]['flip_direction']);
            $this->assertSame(1100, $validated['configuration']['pages'][0]['options']['item_transition_duration_ms']);
            $this->assertSame('top_to_bottom', $validated['configuration']['pages'][0]['options']['item_flip_direction']);
        }
    }

    #[DataProvider('flipDirectionProvider')]
    public function test_each_allowlisted_flip_direction_is_normalized_and_request_validated(string $direction): void
    {
        $page = $this->newsPage('flip');
        $page['transition'] = 'flip';
        $page['flip_direction'] = $direction;
        $page['options']['item_flip_direction'] = $direction;
        $input = [
            'page_transition' => 'flip',
            'page_flip_direction' => $direction,
            'pages' => [$page],
        ];

        $configuration = WallboardConfiguration::normalize($input);

        $this->assertSame($direction, $configuration['page_flip_direction']);
        $this->assertSame($direction, $configuration['pages'][0]['flip_direction']);
        $this->assertSame($direction, $configuration['pages'][0]['options']['item_flip_direction']);

        $validated = $this->validateRequest(new StoreWallboardPlaylistRequest, [
            'name' => 'Fliprichtingen',
            'configuration' => $input,
        ]);

        $this->assertSame($direction, $validated['configuration']['page_flip_direction']);
        $this->assertSame($direction, $validated['configuration']['pages'][0]['flip_direction']);
        $this->assertSame($direction, $validated['configuration']['pages'][0]['options']['item_flip_direction']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function flipDirectionProvider(): iterable
    {
        yield 'links naar rechts' => ['left_to_right'];
        yield 'boven naar beneden' => ['top_to_bottom'];
        yield 'onder naar boven' => ['bottom_to_top'];
        yield 'willekeurig' => ['random'];
    }

    #[DataProvider('newsTransitionProvider')]
    public function test_each_allowlisted_news_transition_is_normalized_and_request_validated(string $transition): void
    {
        $page = $this->newsPage($transition);
        $configuration = WallboardConfiguration::normalize([
            'page_fade_enabled' => false,
            'pages' => [$page],
        ]);

        $this->assertFalse($configuration['page_fade_enabled']);
        $this->assertSame($transition, $configuration['pages'][0]['options']['item_transition']);

        foreach ([new StoreWallboardRequest, new StoreWallboardPlaylistRequest] as $request) {
            $validated = $this->validateRequest($request, [
                'name' => 'Nieuws',
                'configuration' => [
                    'page_fade_enabled' => false,
                    'pages' => [$page],
                ],
            ]);
            $this->assertFalse($validated['configuration']['page_fade_enabled']);
            $this->assertSame(
                $transition,
                $validated['configuration']['pages'][0]['options']['item_transition'],
            );
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function newsTransitionProvider(): iterable
    {
        yield 'Vervagen' => ['fade'];
        yield 'Dissolve' => ['dissolve'];
        yield 'Schuiven' => ['slide'];
        yield 'Flip' => ['flip'];
        yield 'Zachte zoom' => ['zoom'];
        yield 'Wipe' => ['wipe'];
        yield 'Direct wisselen' => ['none'];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_unknown_or_non_string_news_transitions_fail_closed(mixed $transition): void
    {
        $page = $this->newsPage($transition);

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Een niet-toegestane nieuwsitemovergang had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'configuration.pages.0.options.item_transition',
                $exception->errors(),
            );
        }

        $this->assertRequestRejected(
            new StoreWallboardPlaylistRequest,
            ['name' => 'Nieuws', 'configuration' => ['pages' => [$page]]],
            'configuration.pages.0.options.item_transition',
        );
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidTransitionProvider(): iterable
    {
        yield 'unknown string' => ['spin'];
        yield 'wrong casing' => ['Fade'];
        yield 'boolean' => [true];
        yield 'array' => [['fade']];
    }

    #[DataProvider('invalidFlipDirectionProvider')]
    public function test_unknown_or_non_string_flip_directions_fail_closed(mixed $direction): void
    {
        foreach (['global', 'page', 'news'] as $scope) {
            $page = $this->newsPage('flip');
            $input = ['page_transition' => 'flip', 'pages' => [$page]];
            $errorKey = 'configuration.page_flip_direction';
            if ($scope === 'global') {
                $input['page_flip_direction'] = $direction;
            } elseif ($scope === 'page') {
                $input['pages'][0]['flip_direction'] = $direction;
                $errorKey = 'configuration.pages.0.flip_direction';
            } else {
                $input['pages'][0]['options']['item_flip_direction'] = $direction;
                $errorKey = 'configuration.pages.0.options.item_flip_direction';
            }

            try {
                WallboardConfiguration::normalize($input);
                $this->fail("Een ongeldige {$scope}-fliprichting had niet mogen normaliseren.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }

            $this->assertRequestRejected(
                new StoreWallboardPlaylistRequest,
                ['name' => 'Fliprichtingen', 'configuration' => $input],
                $errorKey,
            );
        }
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidFlipDirectionProvider(): iterable
    {
        yield 'unknown string' => ['diagonal'];
        yield 'wrong casing' => ['Left_To_Right'];
        yield 'boolean' => [true];
        yield 'array' => [['left_to_right']];
    }

    public function test_explicit_null_global_flip_direction_is_rejected(): void
    {
        $input = [
            'page_transition' => 'flip',
            'page_flip_direction' => null,
            'pages' => [$this->newsPage('flip')],
        ];

        try {
            WallboardConfiguration::normalize($input);
            $this->fail('Een lege globale fliprichting had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.page_flip_direction', $exception->errors());
        }

        $this->assertRequestRejected(
            new StoreWallboardPlaylistRequest,
            ['name' => 'Fliprichtingen', 'configuration' => $input],
            'configuration.page_flip_direction',
        );
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_invalid_global_and_page_transitions_fail_closed(mixed $transition): void
    {
        foreach (['global', 'page'] as $scope) {
            $page = $this->newsPage();
            $input = ['pages' => [$page]];
            $errorKey = 'configuration.page_transition';
            if ($scope === 'global') {
                $input['page_transition'] = $transition;
            } else {
                $input['pages'][0]['transition'] = $transition;
                $errorKey = 'configuration.pages.0.transition';
            }

            try {
                WallboardConfiguration::normalize($input);
                $this->fail("Een ongeldige {$scope}-paginaovergang had niet mogen normaliseren.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }

            $this->assertRequestRejected(
                new StoreWallboardPlaylistRequest,
                ['name' => 'Nieuws', 'configuration' => $input],
                $errorKey,
            );
        }
    }

    #[DataProvider('invalidTransitionDurationProvider')]
    public function test_transition_durations_are_strictly_bounded(mixed $duration): void
    {
        $pageOverride = $this->newsPage();
        $pageOverride['transition_duration_ms'] = $duration;
        $newsItem = $this->newsPage();
        $newsItem['options']['item_transition_duration_ms'] = $duration;

        foreach ([
            [
                ['page_transition_duration_ms' => $duration, 'pages' => [$this->newsPage()]],
                'configuration.page_transition_duration_ms',
            ],
            [
                ['pages' => [$pageOverride]],
                'configuration.pages.0.transition_duration_ms',
            ],
            [
                ['pages' => [$newsItem]],
                'configuration.pages.0.options.item_transition_duration_ms',
            ],
        ] as [$input, $errorKey]) {
            try {
                WallboardConfiguration::normalize($input);
                $this->fail('Een ongeldige overgangsduur had niet mogen normaliseren.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }

            $this->assertRequestRejected(
                new StoreWallboardPlaylistRequest,
                [
                    'name' => 'Nieuws',
                    'configuration' => $input,
                ],
                $errorKey,
            );
        }
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidTransitionDurationProvider(): iterable
    {
        yield 'below minimum' => [99];
        yield 'above maximum' => [5001];
        yield 'numeric string' => ['720'];
        yield 'fraction' => [720.5];
        yield 'boolean' => [true];
    }

    #[DataProvider('invalidPageFadeProvider')]
    public function test_page_fade_requires_a_strict_boolean(mixed $value): void
    {
        try {
            WallboardConfiguration::normalize([
                'page_fade_enabled' => $value,
                'pages' => [$this->newsPage()],
            ]);
            $this->fail('Een niet-booleaanse paginafade had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.page_fade_enabled', $exception->errors());
        }

        $this->assertRequestRejected(
            new StoreWallboardRequest,
            [
                'name' => 'Nieuws',
                'configuration' => [
                    'page_fade_enabled' => $value,
                    'pages' => [$this->newsPage()],
                ],
            ],
            'configuration.page_fade_enabled',
        );
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidPageFadeProvider(): iterable
    {
        yield 'integer one' => [1];
        yield 'string true' => ['true'];
        yield 'null' => [null];
    }

    public function test_legacy_update_request_accepts_both_transition_settings(): void
    {
        $validated = $this->validateRequest(new UpdateWallboardRequest, [
            'expected_config_version' => 3,
            'configuration' => [
                'page_fade_enabled' => false,
                'pages' => [$this->newsPage('wipe')],
            ],
        ]);

        $this->assertFalse($validated['configuration']['page_fade_enabled']);
        $this->assertSame('wipe', $validated['configuration']['pages'][0]['options']['item_transition']);
    }

    public function test_playlist_update_request_accepts_both_transition_settings(): void
    {
        $validated = $this->validateRequest(new UpdateWallboardPlaylistRequest, [
            'expected_version' => 7,
            'configuration' => [
                'page_fade_enabled' => true,
                'pages' => [$this->newsPage('dissolve')],
            ],
        ]);

        $this->assertTrue($validated['configuration']['page_fade_enabled']);
        $this->assertSame(
            'dissolve',
            $validated['configuration']['pages'][0]['options']['item_transition'],
        );
    }

    /** @return array<string, mixed> */
    private function newsPage(mixed $transition = null): array
    {
        $options = [
            'sources' => ['ndt'],
            'custom_sources' => [],
            'max_items' => 6,
            'item_duration_seconds' => 12,
        ];
        if ($transition !== null) {
            $options['item_transition'] = $transition;
        }

        return [
            'id' => 'news',
            'name' => 'Drone nieuws',
            'type' => 'news',
            'duration_seconds' => 72,
            'options' => $options,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function assertRequestRejected(FormRequest $request, array $payload, string $errorKey): void
    {
        try {
            $this->validateRequest($request, $payload);
            $this->fail('De ongeldige overgangsconfiguratie had niet door requestvalidatie mogen komen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
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
