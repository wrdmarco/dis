<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Validation\ValidationException;

final class TestAlertSpeechContentService
{
    public const DEFAULT_MESSAGE = 'Dit is het wekelijkse proefalarm.';

    private const MAX_LINES = 8;

    private const MAX_TOTAL_CHARACTERS = 800;

    public function __construct(
        private readonly SpeechTemplateService $templates,
        private readonly SpeechPreparedPhraseService $preparedPhrases,
    ) {}

    public function configuredMessage(): string
    {
        return SystemSetting::string('test_alert.message', self::DEFAULT_MESSAGE) ?? self::DEFAULT_MESSAGE;
    }

    /**
     * Return the exact configured body that is delivered for a test alert.
     *
     * Acknowledgement instructions are removed centrally so push delivery,
     * fixed preparation and operational speech can never drift.
     */
    public function deliveredMessage(): string
    {
        $cleaned = preg_replace([
            '/\s*Bevestig deze proefalarmering met Ontvangen(?: in de app)?\.?/iu',
            '/\s*Bevestig ontvangst met de knop Ontvangen\.?/iu',
        ], '', $this->configuredMessage());
        $cleaned = trim((string) $cleaned);

        return $cleaned !== '' ? $cleaned : self::DEFAULT_MESSAGE;
    }

    /**
     * @return list<string>
     */
    public function lines(?string $deliveredMessage = null): array
    {
        $template = $this->templates->template(SpeechTemplateService::PHASE_TEST_ACK);
        $templateLines = $this->templates->render(
            SpeechTemplateService::PHASE_TEST_ACK,
            $template,
            [],
        );

        return $this->validateLines([
            $deliveredMessage ?? $this->deliveredMessage(),
            ...$templateLines,
        ]);
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return list<string>
     */
    public function validateLines(array $lines): array
    {
        if (array_any($lines, static fn (mixed $line): bool => ! is_string($line))) {
            throw ValidationException::withMessages([
                'test_alert_speech' => ['De opgeslagen proefalarmtekst bevat een ongeldige regel.'],
            ]);
        }
        $lines = $this->preparedPhrases->normalizeFixedPhrases(array_values($lines));
        $total = array_sum(array_map(mb_strlen(...), $lines));
        if ($lines === [] || count($lines) > self::MAX_LINES || $total > self::MAX_TOTAL_CHARACTERS) {
            throw ValidationException::withMessages([
                'test_alert_speech' => [
                    'De gecombineerde proefalarmtekst moet uit maximaal 8 regels en 800 tekens bestaan.',
                ],
            ]);
        }

        return $lines;
    }

    /** @param list<string> $lines */
    public function checksum(array $lines): string
    {
        return hash('sha256', json_encode([
            'schema' => 1,
            'phase' => SpeechTemplateService::PHASE_TEST_ACK,
            'lines' => $lines,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
