<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use JsonException;

final class WallboardRichText
{
    public const VERSION = 1;

    public const MAX_BLOCKS = 24;

    public const MAX_LIST_ITEMS = 12;

    public const MAX_RUNS = 160;

    public const MAX_RUN_TEXT_LENGTH = 500;

    public const MAX_MARK_ENTRIES = 4;

    public const MAX_VISIBLE_CHARACTERS = 2000;

    public const MAX_SERIALIZED_BYTES = 16384;

    /** @var list<string> */
    public const TEXT_BLOCK_TYPES = ['heading', 'paragraph', 'quote'];

    /** @var list<string> */
    public const LIST_BLOCK_TYPES = ['bullet_list', 'numbered_list'];

    /** @var list<string> */
    public const ALIGNMENTS = ['left', 'center'];

    /** @var list<string> */
    public const MARKS = ['bold', 'italic'];

    /**
     * Accept the former plain-text body only as a migration input. Every
     * normalized configuration emits the versioned rich-text document.
     *
     * @param  array<string, mixed>  $options
     * @return array{content: array<string, mixed>}
     */
    public static function normalizeOptions(array $options, string $field): array
    {
        if (array_diff(array_keys($options), ['body', 'content']) !== []) {
            self::invalid($field, 'Een mededeling bevat alleen het opgemaakte document.');
        }

        $hasLegacyBody = array_key_exists('body', $options);
        $hasContent = array_key_exists('content', $options);
        if ($hasLegacyBody && $hasContent) {
            self::invalid($field, 'Een mededeling mag niet tegelijk oude en nieuwe berichtinhoud bevatten.');
        }
        if (! $hasLegacyBody && ! $hasContent) {
            self::invalid($field.'.content', 'Een mededeling heeft inhoud nodig.');
        }

        return [
            'content' => $hasContent
                ? self::normalizeDocument($options['content'], $field.'.content')
                : self::fromLegacyBody($options['body'], $field.'.body'),
        ];
    }

    /**
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    public static function normalizeDocument(mixed $document, string $field): array
    {
        if (! is_array($document)
            || array_diff(array_keys($document), ['version', 'blocks']) !== []
            || ! array_key_exists('version', $document)
            || ! array_key_exists('blocks', $document)) {
            self::invalid($field, 'Het opgemaakte document heeft een ongeldige structuur.');
        }

        self::assertSerializedSize($document, $field);

        if (($document['version'] ?? null) !== self::VERSION) {
            self::invalid($field.'.version', 'Deze versie van de mededelingenopmaak wordt niet ondersteund.');
        }

        $blocks = $document['blocks'];
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            self::invalid($field.'.blocks', 'De inhoudsblokken moeten als een lijst worden aangeleverd.');
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            self::invalid($field.'.blocks', 'Een mededeling kan maximaal 24 inhoudsblokken bevatten.');
        }

        $runCount = 0;
        $visibleCharacters = 0;
        $normalizedBlocks = [];
        foreach ($blocks as $blockIndex => $block) {
            $normalized = self::normalizeBlock(
                $block,
                $field.'.blocks.'.$blockIndex,
                $runCount,
                $visibleCharacters,
            );
            if ($normalized !== null) {
                $normalizedBlocks[] = $normalized;
            }
        }

        if ($normalizedBlocks === []) {
            self::invalid($field.'.blocks', 'Een mededeling heeft minimaal één niet-leeg inhoudsblok nodig.');
        }
        if ($visibleCharacters > self::MAX_VISIBLE_CHARACTERS) {
            self::invalid($field, 'Een mededeling kan maximaal 2000 zichtbare tekens bevatten.');
        }

        $normalized = [
            'version' => self::VERSION,
            'blocks' => $normalizedBlocks,
        ];
        self::assertSerializedSize($normalized, $field);

        return $normalized;
    }

    /**
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    private static function fromLegacyBody(mixed $body, string $field): array
    {
        if (! is_string($body)) {
            self::invalid($field, 'De bestaande mededeling moet platte tekst zijn.');
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = trim($body);
        if ($body === ''
            || mb_strlen($body) > self::MAX_VISIBLE_CHARACTERS
            || $body !== strip_tags($body)
            || self::containsDisallowedControlCharacters($body)) {
            self::invalid($field, 'De bestaande mededeling heeft maximaal 2000 tekens platte tekst nodig.');
        }

        return self::normalizeDocument([
            'version' => self::VERSION,
            'blocks' => [[
                'type' => 'paragraph',
                'align' => 'center',
                'runs' => [['text' => $body]],
            ]],
        ], str_ends_with($field, '.body') ? substr($field, 0, -5).'.content' : $field);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeBlock(
        mixed $block,
        string $field,
        int &$runCount,
        int &$visibleCharacters,
    ): ?array {
        if (! is_array($block) || ! is_string($block['type'] ?? null)) {
            self::invalid($field, 'Dit inhoudsblok is ongeldig.');
        }

        $type = $block['type'];
        if (in_array($type, self::TEXT_BLOCK_TYPES, true)) {
            if (array_diff(array_keys($block), ['type', 'align', 'runs']) !== []
                || ! array_key_exists('align', $block)
                || ! array_key_exists('runs', $block)) {
                self::invalid($field, 'Een tekstblok bevat alleen type, uitlijning en tekstdelen.');
            }
            if (! is_string($block['align']) || ! in_array($block['align'], self::ALIGNMENTS, true)) {
                self::invalid($field.'.align', 'Kies links of gecentreerd als uitlijning.');
            }

            $runs = self::normalizeRuns($block['runs'], $field.'.runs', $runCount, $visibleCharacters);
            if ($runs === []) {
                return null;
            }

            return [
                'type' => $type,
                'align' => $block['align'],
                'runs' => $runs,
            ];
        }

        if (! in_array($type, self::LIST_BLOCK_TYPES, true)) {
            self::invalid($field.'.type', 'Dit type inhoudsblok wordt niet ondersteund.');
        }
        if (array_diff(array_keys($block), ['type', 'items']) !== []
            || ! array_key_exists('items', $block)
            || ! is_array($block['items'])
            || ! array_is_list($block['items'])) {
            self::invalid($field, 'Een lijstblok bevat alleen type en lijstregels.');
        }
        if (count($block['items']) > self::MAX_LIST_ITEMS) {
            self::invalid($field.'.items', 'Een lijst kan maximaal twaalf regels bevatten.');
        }

        $items = [];
        foreach ($block['items'] as $itemIndex => $item) {
            $itemField = $field.'.items.'.$itemIndex;
            if (! is_array($item)
                || array_diff(array_keys($item), ['runs']) !== []
                || ! array_key_exists('runs', $item)) {
                self::invalid($itemField, 'Een lijstregel bevat alleen tekstdelen.');
            }

            $runs = self::normalizeRuns($item['runs'], $itemField.'.runs', $runCount, $visibleCharacters);
            if ($runs !== []) {
                $items[] = ['runs' => $runs];
            }
        }

        return $items === [] ? null : ['type' => $type, 'items' => $items];
    }

    /**
     * @return list<array{text: string, marks?: list<string>}>
     */
    private static function normalizeRuns(
        mixed $runs,
        string $field,
        int &$runCount,
        int &$visibleCharacters,
    ): array {
        if (! is_array($runs) || ! array_is_list($runs)) {
            self::invalid($field, 'Tekstdelen moeten als een lijst worden aangeleverd.');
        }

        $runCount += count($runs);
        if ($runCount > self::MAX_RUNS) {
            self::invalid($field, 'Een mededeling kan maximaal 160 tekstdelen bevatten.');
        }

        $normalized = [];
        foreach ($runs as $runIndex => $run) {
            $runField = $field.'.'.$runIndex;
            if (! is_array($run)
                || array_diff(array_keys($run), ['text', 'marks']) !== []
                || ! array_key_exists('text', $run)
                || ! is_string($run['text'])) {
                self::invalid($runField, 'Een tekstdeel bevat alleen tekst en optionele opmaakmarkeringen.');
            }

            $text = str_replace(["\r\n", "\r"], "\n", $run['text']);
            if (mb_strlen($text) > self::MAX_RUN_TEXT_LENGTH) {
                self::invalid($runField.'.text', 'Een afzonderlijk tekstdeel kan maximaal 500 tekens bevatten.');
            }
            if (self::containsDisallowedControlCharacters($text)) {
                self::invalid($runField.'.text', 'Een tekstdeel bevat niet-ondersteunde besturingstekens.');
            }

            $marks = $run['marks'] ?? [];
            if (! is_array($marks) || ! array_is_list($marks) || count($marks) > self::MAX_MARK_ENTRIES) {
                self::invalid($runField.'.marks', 'De tekstopmaak is ongeldig.');
            }
            $seenMarks = [];
            foreach ($marks as $markIndex => $mark) {
                if (! is_string($mark) || ! in_array($mark, self::MARKS, true)) {
                    self::invalid($runField.'.marks.'.$markIndex, 'Deze tekstopmaak wordt niet ondersteund.');
                }
                $seenMarks[$mark] = true;
            }
            $canonicalMarks = array_values(array_filter(
                self::MARKS,
                static fn (string $mark): bool => isset($seenMarks[$mark]),
            ));

            if ($text === '') {
                continue;
            }
            $candidate = ['text' => $text];
            if ($canonicalMarks !== []) {
                $candidate['marks'] = $canonicalMarks;
            }

            $previousIndex = array_key_last($normalized);
            if ($previousIndex !== null
                && ($normalized[$previousIndex]['marks'] ?? []) === $canonicalMarks
                && mb_strlen($normalized[$previousIndex]['text'].$text) <= self::MAX_RUN_TEXT_LENGTH) {
                $normalized[$previousIndex]['text'] .= $text;
            } else {
                $normalized[] = $candidate;
            }
        }

        $normalized = self::trimRunBoundaries($normalized);
        foreach ($normalized as $run) {
            $visibleCharacters += mb_strlen($run['text']);
            if ($visibleCharacters > self::MAX_VISIBLE_CHARACTERS) {
                self::invalid($field, 'Een mededeling kan maximaal 2000 zichtbare tekens bevatten.');
            }
        }

        return $normalized;
    }

    /**
     * @param  list<array{text: string, marks?: list<string>}>  $runs
     * @return list<array{text: string, marks?: list<string>}>
     */
    private static function trimRunBoundaries(array $runs): array
    {
        while ($runs !== []) {
            $runs[0]['text'] = ltrim($runs[0]['text']);
            if ($runs[0]['text'] !== '') {
                break;
            }
            array_shift($runs);
        }
        while ($runs !== []) {
            $last = array_key_last($runs);
            $runs[$last]['text'] = rtrim($runs[$last]['text']);
            if ($runs[$last]['text'] !== '') {
                break;
            }
            array_pop($runs);
        }

        return array_values($runs);
    }

    private static function containsDisallowedControlCharacters(string $text): bool
    {
        return preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', $text) === 1;
    }

    /** @param array<string, mixed> $document */
    private static function assertSerializedSize(array $document, string $field): void
    {
        try {
            $encoded = json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            self::invalid($field, 'Het opgemaakte document bevat ongeldige tekstgegevens.');
        }

        if (strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            self::invalid($field, 'Het opgemaakte document is te groot.');
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
