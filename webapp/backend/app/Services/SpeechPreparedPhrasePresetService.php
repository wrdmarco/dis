<?php

namespace App\Services;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SpeechPreparedPhrasePresetService
{
    public const WEEKLY_TEST_ALERT = 'weekly_test_alert';

    public function __construct(
        private readonly TestAlertSpeechContentService $testAlertSpeech,
        private readonly SpeechPreparedPhraseService $preparedPhrases,
        private readonly AuditService $audit,
    ) {}

    /** @return list<array{id:string,label:string,description:string,preview_lines:list<string>,phrase_count:int}> */
    public function all(): array
    {
        return [$this->preset(self::WEEKLY_TEST_ALERT)];
    }

    /**
     * @return array{
     *     preset:array{id:string,label:string,description:string,preview_lines:list<string>,phrase_count:int},
     *     preparations:list<array<string,mixed>>
     * }
     */
    public function prepare(string $presetId, User $actor): array
    {
        $preset = $this->preset($presetId);
        $preparations = $this->preparedPhrases->create(
            'fixed_phrase',
            $preset['preview_lines'],
            $actor,
        );
        $this->audit->record(
            'speech.preparation_preset_requested',
            'speech_prepared_phrase_presets',
            $actor,
            [
                'preset_id' => $preset['id'],
                'phrase_count' => $preset['phrase_count'],
                'queued_count' => count(array_filter(
                    $preparations,
                    static fn (array $preparation): bool => in_array(
                        $preparation['status'] ?? null,
                        ['queued', 'processing'],
                        true,
                    ),
                )),
            ],
        );

        return [
            'preset' => $preset,
            'preparations' => $preparations,
        ];
    }

    /** @return array{id:string,label:string,description:string,preview_lines:list<string>,phrase_count:int} */
    private function preset(string $presetId): array
    {
        if ($presetId !== self::WEEKLY_TEST_ALERT) {
            throw new NotFoundHttpException('Speech preparation preset not found.');
        }

        $lines = $this->testAlertSpeech->lines();

        return [
            'id' => self::WEEKLY_TEST_ALERT,
            'label' => 'Wekelijks proefalarm',
            'description' => 'De actuele proefalarmtekst en de vaste regels van het proefalarmeringssjabloon.',
            'preview_lines' => $lines,
            'phrase_count' => count($lines),
        ];
    }
}
