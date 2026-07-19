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

final class WallboardQuoteConfigurationTest extends TestCase
{
    public function test_quote_page_normalizes_admin_managed_plain_text_and_optional_author(): void
    {
        $page = $this->page([
            ['text' => '  Veilig vliegen begint met een goede voorbereiding.  ', 'author' => '  Team Operatie  '],
            ['text' => 'Controleer altijd de actuele omstandigheden.', 'author' => ''],
        ]);

        $configuration = WallboardConfiguration::normalize(['pages' => [$page]]);

        $this->assertContains('quote', WallboardConfiguration::PAGE_TYPES);
        $this->assertSame([
            ['text' => 'Veilig vliegen begint met een goede voorbereiding.', 'author' => 'Team Operatie'],
            ['text' => 'Controleer altijd de actuele omstandigheden.'],
        ], $configuration['pages'][0]['options']['quotes']);

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$page]],
            ]);
            $this->assertSame('quote', $validated['configuration']['pages'][0]['type']);
            $this->assertCount(2, $validated['configuration']['pages'][0]['options']['quotes']);
        }
    }

    #[DataProvider('invalidQuoteProvider')]
    public function test_quote_page_fails_closed_for_invalid_content(array $quotes, string $errorKey): void
    {
        $page = $this->page($quotes);

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Ongeldige quoteconfiguratie had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            try {
                $this->validateRequest($request, [
                    ...$basePayload,
                    'configuration' => ['pages' => [$page]],
                ]);
                $this->fail('Ongeldige quoteconfiguratie had niet door requestvalidatie mogen komen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }
        }
    }

    /** @return iterable<string, array{0: array<mixed>, 1: string}> */
    public static function invalidQuoteProvider(): iterable
    {
        yield 'lege lijst' => [[], 'configuration.pages.0.options.quotes'];
        yield 'lege tekst' => [[['text' => '   ']], 'configuration.pages.0.options.quotes.0.text'];
        yield 'te lange tekst' => [[['text' => str_repeat('x', 501)]], 'configuration.pages.0.options.quotes.0.text'];
        yield 'te lange auteur' => [[['text' => 'Geldig', 'author' => str_repeat('x', 121)]], 'configuration.pages.0.options.quotes.0.author'];
        yield 'onbekend veld' => [[['text' => 'Geldig', 'url' => 'https://example.test']], 'configuration.pages.0.options.quotes.0'];
        yield 'te veel quotes' => [array_fill(0, 51, ['text' => 'Geldig']), 'configuration.pages.0.options.quotes'];
    }

    /** @return list<array{0: FormRequest, 1: array<string, int|string>}> */
    private function requestContracts(): array
    {
        return [
            [new StoreWallboardRequest, ['name' => 'Dagquote']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Dagquoteplaylist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ];
    }

    /** @param array<mixed> $quotes
     * @return array<string, mixed>
     */
    private function page(array $quotes): array
    {
        return [
            'id' => 'quote-van-de-dag',
            'name' => 'Quote van de dag',
            'type' => 'quote',
            'duration_seconds' => 30,
            'options' => ['quotes' => $quotes],
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
