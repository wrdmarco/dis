<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Support\WallboardConfiguration;
use App\Support\WallboardRichText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardRichTextConfigurationTest extends TestCase
{
    public function test_legacy_plain_text_is_returned_as_canonical_rich_text_without_a_database_migration(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->page(['body' => "  Briefing om 14:00.\r\nNeem uw uitrusting mee.  "])],
        ]);

        $this->assertSame([
            'content' => [
                'version' => 1,
                'blocks' => [[
                    'type' => 'paragraph',
                    'align' => 'center',
                    'runs' => [[
                        'text' => "Briefing om 14:00.\nNeem uw uitrusting mee.",
                    ]],
                ]],
            ],
        ], $configuration['pages'][0]['options']);
        $this->assertArrayNotHasKey('body', $configuration['pages'][0]['options']);
    }

    public function test_rich_text_is_canonicalized_to_the_small_allowlisted_document(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->page(['content' => [
                'version' => 1,
                'blocks' => [
                    ['type' => 'paragraph', 'align' => 'center', 'runs' => [['text' => '   ']]],
                    [
                        'type' => 'heading',
                        'align' => 'center',
                        'runs' => [
                            ['text' => '  Belangrijk ', 'marks' => ['italic', 'bold', 'bold']],
                            ['text' => 'bericht  ', 'marks' => ['bold', 'italic']],
                        ],
                    ],
                    [
                        'type' => 'bullet_list',
                        'items' => [
                            ['runs' => [['text' => ' Eerste punt ']]],
                            ['runs' => [['text' => ' ']]],
                        ],
                    ],
                ],
            ]])],
        ]);

        $this->assertSame([
            'content' => [
                'version' => 1,
                'blocks' => [
                    [
                        'type' => 'heading',
                        'align' => 'center',
                        'runs' => [[
                            'text' => 'Belangrijk bericht',
                            'marks' => ['bold', 'italic'],
                        ]],
                    ],
                    [
                        'type' => 'bullet_list',
                        'items' => [[
                            'runs' => [['text' => 'Eerste punt']],
                        ]],
                    ],
                ],
            ],
        ], $configuration['pages'][0]['options']);
    }

    public function test_structured_text_that_looks_like_html_remains_inert_text(): void
    {
        $content = $this->document('<script>window.pwned=true</script>');
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->page(['content' => $content])],
        ]);

        $this->assertSame(
            '<script>window.pwned=true</script>',
            $configuration['pages'][0]['options']['content']['blocks'][0]['runs'][0]['text'],
        );
    }

    public function test_every_admin_configuration_request_accepts_the_versioned_contract(): void
    {
        $page = $this->page(['content' => $this->document('Operationele mededeling')]);

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$page]],
            ]);

            $this->assertSame(
                'Operationele mededeling',
                $validated['configuration']['pages'][0]['options']['content']['blocks'][0]['runs'][0]['text'],
            );
        }
    }

    public function test_every_admin_configuration_request_still_accepts_legacy_plain_text_during_migration(): void
    {
        $page = $this->page(['body' => 'Bestaande platte mededeling']);

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$page]],
            ]);

            $this->assertSame(
                'Bestaande platte mededeling',
                $validated['configuration']['pages'][0]['options']['body'],
            );
        }
    }

    public function test_legacy_and_versioned_content_cannot_shadow_each_other(): void
    {
        $page = $this->page([
            'body' => 'Oude tekst',
            'content' => $this->document('Nieuwe tekst'),
        ]);

        $this->assertConfigurationRejected($page, 'configuration.pages.0.options');
        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $this->assertRequestRejected($request, $basePayload, $page, 'configuration.pages.0.options');
        }
    }

    #[DataProvider('invalidDocumentProvider')]
    public function test_configuration_fails_closed_for_invalid_rich_text(array $content, string $errorKey): void
    {
        $this->assertConfigurationRejected($this->page(['content' => $content]), $errorKey);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidDocumentProvider(): iterable
    {
        yield 'unsupported document version' => [[
            'version' => 2,
            'blocks' => [],
        ], 'configuration.pages.0.options.content.version'];

        yield 'document has an unknown field' => [[
            'version' => 1,
            'blocks' => [],
            'html' => '<script>alert(1)</script>',
        ], 'configuration.pages.0.options.content'];

        yield 'empty document' => [[
            'version' => 1,
            'blocks' => [],
        ], 'configuration.pages.0.options.content.blocks'];

        yield 'unsupported block type' => [[
            'version' => 1,
            'blocks' => [[
                'type' => 'iframe',
                'align' => 'center',
                'runs' => [['text' => 'Onveilig']],
            ]],
        ], 'configuration.pages.0.options.content.blocks.0.type'];

        yield 'block contains raw style' => [[
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'align' => 'center',
                'style' => 'background:url(javascript:alert(1))',
                'runs' => [['text' => 'Onveilig']],
            ]],
        ], 'configuration.pages.0.options.content.blocks.0'];

        yield 'unsupported alignment' => [[
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'align' => 'justify',
                'runs' => [['text' => 'Niet ondersteund']],
            ]],
        ], 'configuration.pages.0.options.content.blocks.0.align'];

        yield 'unsupported mark' => [[
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'align' => 'left',
                'runs' => [['text' => 'Klik', 'marks' => ['link']]],
            ]],
        ], 'configuration.pages.0.options.content.blocks.0.runs.0.marks.0'];

        yield 'run contains an event field' => [[
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'align' => 'left',
                'runs' => [['text' => 'Onveilig', 'onclick' => 'alert(1)']],
            ]],
        ], 'configuration.pages.0.options.content.blocks.0.runs.0'];

        yield 'too many visible characters' => [[
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'align' => 'left',
                'runs' => array_fill(0, 5, ['text' => str_repeat('a', 500)]),
            ]],
        ], 'configuration.pages.0.options.content.blocks.0.runs'];

        yield 'too many blocks' => [[
            'version' => 1,
            'blocks' => array_fill(0, WallboardRichText::MAX_BLOCKS + 1, [
                'type' => 'paragraph',
                'align' => 'left',
                'runs' => [['text' => 'Regel']],
            ]),
        ], 'configuration.pages.0.options.content.blocks'];

        yield 'too many list items' => [[
            'version' => 1,
            'blocks' => [[
                'type' => 'bullet_list',
                'items' => array_fill(0, WallboardRichText::MAX_LIST_ITEMS + 1, [
                    'runs' => [['text' => 'Punt']],
                ]),
            ]],
        ], 'configuration.pages.0.options.content.blocks.0.items'];
    }

    public function test_admin_requests_reject_unknown_rich_text_fields_before_persistence(): void
    {
        $page = $this->page(['content' => [
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'align' => 'center',
                'runs' => [['text' => 'Bericht', 'html' => '<b>Bericht</b>']],
            ]],
        ]]);

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $this->assertRequestRejected(
                $request,
                $basePayload,
                $page,
                'configuration.pages.0.options.content.blocks.0.runs.0',
            );
        }
    }

    public function test_plain_text_html_and_disallowed_control_characters_remain_rejected(): void
    {
        $this->assertConfigurationRejected(
            $this->page(['body' => '<strong>Niet opslaan</strong>']),
            'configuration.pages.0.options.body',
        );
        $this->assertConfigurationRejected(
            $this->page(['content' => $this->document("Regel\x00twee")]),
            'configuration.pages.0.options.content.blocks.0.runs.0.text',
        );
    }

    /**
     * @return list<array{0: FormRequest, 1: array<string, int|string>}>
     */
    private function requestContracts(): array
    {
        return [
            [new StoreWallboardRequest, ['name' => 'Mededelingenscherm']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Mededelingenplaylist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    private function document(string $text): array
    {
        return [
            'version' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'align' => 'center',
                'runs' => [['text' => $text]],
            ]],
        ];
    }

    /** @param array<string, mixed> $options */
    private function page(array $options): array
    {
        return [
            'id' => 'message',
            'name' => 'Naam voor beheer',
            'type' => 'message',
            'duration_seconds' => 30,
            'options' => $options,
        ];
    }

    /** @param array<string, mixed> $page */
    private function assertConfigurationRejected(array $page, string $errorKey): void
    {
        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Ongeldige mededelingenopmaak had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }

    /**
     * @param  array<string, int|string>  $basePayload
     * @param  array<string, mixed>  $page
     */
    private function assertRequestRejected(
        FormRequest $request,
        array $basePayload,
        array $page,
        string $errorKey,
    ): void {
        try {
            $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$page]],
            ]);
            $this->fail('Ongeldige mededelingenopmaak had niet door requestvalidatie mogen komen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
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
