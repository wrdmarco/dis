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
        $this->assertSame('fade', $configuration['pages'][0]['options']['item_transition']);
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
