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

final class WallboardPhotoCarouselTransitionConfigurationTest extends TestCase
{
    public function test_legacy_photo_carousels_receive_backward_compatible_transition_defaults(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->photoCarouselPage()],
        ]);

        $options = $configuration['pages'][0]['options'];
        $this->assertSame('fade', $options['item_transition']);
        $this->assertSame(720, $options['item_transition_duration_ms']);
        $this->assertSame('left_to_right', $options['item_flip_direction']);
    }

    public function test_photo_carousel_transition_contract_is_normalized_and_accepted_by_every_write_request(): void
    {
        $page = $this->photoCarouselPage([
            'item_transition' => 'flip',
            'item_transition_duration_ms' => 1350,
            'item_flip_direction' => 'bottom_to_top',
        ]);
        $configuration = ['pages' => [$page]];

        $normalized = WallboardConfiguration::normalize($configuration);
        $this->assertSame(
            [
                'media_playlist_id' => '01kxt8sbrpmqm7x2arsmffemff',
                'item_duration_seconds' => 12,
                'item_transition' => 'flip',
                'item_transition_duration_ms' => 1350,
                'item_flip_direction' => 'bottom_to_top',
            ],
            $normalized['pages'][0]['options'],
        );

        foreach ($this->writeRequests($configuration) as [$request, $payload]) {
            $validated = $this->validateRequest($request, $payload);

            $this->assertSame('flip', $validated['configuration']['pages'][0]['options']['item_transition']);
            $this->assertSame(1350, $validated['configuration']['pages'][0]['options']['item_transition_duration_ms']);
            $this->assertSame('bottom_to_top', $validated['configuration']['pages'][0]['options']['item_flip_direction']);
        }
    }

    #[DataProvider('invalidTransitionOptionProvider')]
    public function test_photo_carousel_transition_options_fail_closed(
        string $field,
        mixed $value,
    ): void {
        $page = $this->photoCarouselPage([$field => $value]);
        $configuration = ['pages' => [$page]];
        $errorKey = "configuration.pages.0.options.{$field}";

        try {
            WallboardConfiguration::normalize($configuration);
            $this->fail('Een ongeldige foto-overgang had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        foreach ($this->writeRequests($configuration) as [$request, $payload]) {
            $this->assertRequestRejected($request, $payload, $errorKey);
        }
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function invalidTransitionOptionProvider(): iterable
    {
        yield 'unknown transition' => ['item_transition', 'spin'];
        yield 'non-string transition' => ['item_transition', true];
        yield 'duration below minimum' => ['item_transition_duration_ms', 99];
        yield 'duration above maximum' => ['item_transition_duration_ms', 5001];
        yield 'duration as numeric string' => ['item_transition_duration_ms', '720'];
        yield 'fractional duration' => ['item_transition_duration_ms', 720.5];
        yield 'unknown flip direction' => ['item_flip_direction', 'diagonal'];
        yield 'non-string flip direction' => ['item_flip_direction', ['left_to_right']];
    }

    public function test_photo_transition_options_remain_rejected_on_unrelated_page_types(): void
    {
        $configuration = [
            'pages' => [[
                'id' => 'map',
                'name' => 'Kaart',
                'type' => 'map',
                'duration_seconds' => 30,
                'options' => [
                    'item_transition' => 'fade',
                    'item_transition_duration_ms' => 720,
                    'item_flip_direction' => 'left_to_right',
                ],
            ]],
        ];
        $errorKey = 'configuration.pages.0.options';

        try {
            WallboardConfiguration::normalize($configuration);
            $this->fail('Foto-overgangsopties hadden niet op een kaartpagina mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        foreach ($this->writeRequests($configuration) as [$request, $payload]) {
            $this->assertRequestRejected($request, $payload, $errorKey);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function photoCarouselPage(array $overrides = []): array
    {
        return [
            'id' => 'photos',
            'name' => 'Foto\'s',
            'type' => 'photo_carousel',
            'duration_seconds' => 36,
            'options' => [
                'media_playlist_id' => '01KXT8SBRPMQM7X2ARSMFFEMFF',
                'item_duration_seconds' => 12,
                ...$overrides,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<array{0: FormRequest, 1: array<string, mixed>}>
     */
    private function writeRequests(array $configuration): array
    {
        return [
            [new StoreWallboardRequest, ['name' => 'Scherm', 'configuration' => $configuration]],
            [new UpdateWallboardRequest, ['expected_config_version' => 2, 'configuration' => $configuration]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Playlist', 'configuration' => $configuration]],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 2, 'configuration' => $configuration]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function assertRequestRejected(FormRequest $request, array $payload, string $errorKey): void
    {
        try {
            $this->validateRequest($request, $payload);
            $this->fail('De ongeldige foto-overgang had niet door requestvalidatie mogen komen.');
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
