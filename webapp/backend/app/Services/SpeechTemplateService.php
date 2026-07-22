<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\SystemSetting;
use Illuminate\Validation\ValidationException;

final class SpeechTemplateService
{
    public const PHASE_ATTENDANCE = 'attendance';

    public const PHASE_AVAILABILITY = 'availability';

    public const PHASE_TEST_ACK = 'test_ack';

    private const ALLOWED_TOKENS = [
        self::PHASE_AVAILABILITY => ['place'],
        self::PHASE_ATTENDANCE => ['title', 'street', 'house_number', 'postcode', 'place'],
        self::PHASE_TEST_ACK => [],
    ];

    private const DEFAULTS = [
        self::PHASE_AVAILABILITY => [
            'Voorwaarschuwing voor een mogelijke inzet in {place}.',
            'Open de D.I.S.-app en geef je beschikbaarheid door.',
        ],
        self::PHASE_ATTENDANCE => [
            'Alarmering voor {title}.',
            'Adres: {street} {house_number}, {postcode} {place}.',
            'Open de D.I.S.-app en geef aan of je komt.',
        ],
        self::PHASE_TEST_ACK => [
            'Dit is een proefalarmering.',
            'Open de D.I.S.-app en bevestig ontvangst.',
        ],
    ];

    public function __construct(private readonly SpeechAddressNormalizer $addresses) {}

    /** @return list<string> */
    public function phases(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** @return array<string, list<string>> */
    public function settings(): array
    {
        $templates = [];
        foreach ($this->phases() as $phase) {
            $stored = SystemSetting::value('speech.templates.'.$phase, self::DEFAULTS[$phase]);
            try {
                $templates[$phase] = $this->validate($phase, $stored);
            } catch (ValidationException) {
                $templates[$phase] = self::DEFAULTS[$phase];
            }
        }

        return $templates;
    }

    /** @return list<array{phase:string,label:string,allowed_tokens:list<string>,example_rendered_lines:list<string>,checksum:string}> */
    public function definitions(): array
    {
        $templates = $this->settings();

        return array_map(fn (string $phase): array => [
            'phase' => $phase,
            'label' => match ($phase) {
                self::PHASE_AVAILABILITY => 'Vooraankondiging',
                self::PHASE_ATTENDANCE => 'Definitieve alarmering',
                default => 'Proefalarmering',
            },
            'allowed_tokens' => self::ALLOWED_TOKENS[$phase],
            'example_rendered_lines' => $this->render($phase, $templates[$phase], $this->exampleContext($phase)),
            'checksum' => $this->checksum($phase, $templates[$phase]),
        ], $this->phases());
    }

    /** @return list<string> */
    public function allowedTokens(string $phase): array
    {
        $this->assertPhase($phase);

        return self::ALLOWED_TOKENS[$phase];
    }

    /** @return list<string> */
    public function template(string $phase): array
    {
        $this->assertPhase($phase);

        return $this->settings()[$phase];
    }

    /** @return array<string, string> */
    public function contextForIncident(string $phase, Incident $incident): array
    {
        $this->assertPhase($phase);
        if ($phase === self::PHASE_AVAILABILITY) {
            $parts = $this->addresses->parts($incident->location_label);

            return ['place' => $parts['place'] !== '' ? $parts['place'] : 'de regio'];
        }
        if ($phase === self::PHASE_TEST_ACK) {
            return [];
        }

        return ['title' => $this->addresses->plain($incident->title)]
            + $this->addresses->parts($incident->location_label);
    }

    /** @param mixed $lines @return list<string> */
    public function validate(string $phase, mixed $lines): array
    {
        $this->assertPhase($phase);
        if (! is_array($lines) || ! array_is_list($lines) || $lines === [] || count($lines) > 8) {
            throw ValidationException::withMessages([
                "templates.$phase" => ['Een spraaksjabloon bevat 1 tot en met 8 regels.'],
            ]);
        }

        $validated = [];
        $total = 0;
        foreach ($lines as $index => $line) {
            if (! is_string($line)) {
                throw ValidationException::withMessages(["templates.$phase.$index" => ['Elke regel moet tekst zijn.']]);
            }
            $line = trim($line);
            if ($line === '' || mb_strlen($line) > 240 || preg_match('/[\r\n\x00-\x1F\x7F]/u', $line) === 1) {
                throw ValidationException::withMessages(["templates.$phase.$index" => ['De regel is leeg, te lang of bevat onveilige tekens.']]);
            }
            if (str_contains($line, '<') || str_contains($line, '>')
                || preg_match('/&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);/iu', $line) === 1) {
                throw ValidationException::withMessages([
                    "templates.$phase.$index" => ['SSML, HTML, entiteiten en andere markup zijn niet toegestaan.'],
                ]);
            }
            $total += mb_strlen($line);
            if ($total > 800) {
                throw ValidationException::withMessages(["templates.$phase" => ['Het volledige spraaksjabloon is te lang.']]);
            }
            preg_match_all('/\{([a-z_]+)\}/D', $line, $matches);
            foreach ($matches[1] ?? [] as $token) {
                if (! in_array($token, self::ALLOWED_TOKENS[$phase], true)) {
                    throw ValidationException::withMessages([
                        "templates.$phase.$index" => ["Token {{$token}} is niet toegestaan in deze fase."],
                    ]);
                }
            }
            $withoutTokens = preg_replace('/\{[a-z_]+\}/', '', $line) ?? '';
            if (str_contains($withoutTokens, '{') || str_contains($withoutTokens, '}')) {
                throw ValidationException::withMessages(["templates.$phase.$index" => ['De regel bevat een ongeldig token.']]);
            }
            $validated[] = $line;
        }

        return $validated;
    }

    /** @param list<string> $lines @param array<string, string> $context @return list<string> */
    public function render(string $phase, array $lines, array $context): array
    {
        $lines = $this->validate($phase, $lines);
        $allowed = array_flip(self::ALLOWED_TOKENS[$phase]);
        $safeContext = array_intersect_key($context, $allowed);

        $renderedLines = array_map(function (string $line) use ($safeContext): string {
            $rendered = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $match) use ($safeContext): string {
                return (string) ($safeContext[$match[1]] ?? '');
            }, $line);
            $rendered = preg_replace('/\s+([,.!?;:])/u', '$1', (string) $rendered);
            $rendered = preg_replace('/\s+/u', ' ', (string) $rendered);

            return trim((string) $rendered);
        }, $lines);

        $total = 0;
        foreach ($renderedLines as $index => $rendered) {
            $total += mb_strlen($rendered);
            if ($rendered === '' || mb_strlen($rendered) > 240 || $total > 800
                || preg_match('/[\r\n\x00-\x1F\x7F]/u', $rendered) === 1
                || preg_match('/[\p{L}\p{N}]/u', $rendered) !== 1
                || str_contains($rendered, '<') || str_contains($rendered, '>')
                || preg_match('/&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);/iu', $rendered) === 1) {
                throw ValidationException::withMessages([
                    "templates.$phase.$index" => ['De gerenderde spraakregel is leeg, te lang of bevat onveilige tekst.'],
                ]);
            }
        }

        return $renderedLines;
    }

    /** @param list<string> $lines */
    public function checksum(string $phase, array $lines): string
    {
        return hash('sha256', json_encode([
            'schema' => 1,
            'phase' => $phase,
            'lines' => $this->validate($phase, $lines),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, string> */
    public function exampleContext(string $phase): array
    {
        return match ($phase) {
            self::PHASE_AVAILABILITY => ['place' => 'Utrecht'],
            self::PHASE_ATTENDANCE => [
                'title' => 'zoekactie',
                'street' => 'Maliebaan',
                'house_number' => '12 A',
                'postcode' => '3 5 8 1 C P',
                'place' => 'Utrecht',
            ],
            self::PHASE_TEST_ACK => [],
            default => throw new \LogicException('Unknown speech phase.'),
        };
    }

    private function assertPhase(string $phase): void
    {
        if (! array_key_exists($phase, self::DEFAULTS)) {
            throw ValidationException::withMessages(['phase' => ['De gekozen spraakfase is ongeldig.']]);
        }
    }
}
