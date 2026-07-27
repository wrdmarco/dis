<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Repositories\DeploymentReferenceSequenceRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class DeploymentReferenceService
{
    public const SETTING_KEY = 'deployment.reference_template';

    public const DEFAULT_TEMPLATE = 'DIS-{{date}}-{{time}}-{{random}}';

    private const ALLOWED_TOKENS = [
        'date',
        'time',
        'sequence',
        'random',
    ];

    private const MAX_TEMPLATE_LENGTH = 160;

    private const MAX_REFERENCE_LENGTH = 240;

    public function __construct(
        private readonly DeploymentReferenceSequenceRepository $sequences,
    ) {}

    public function validateTemplate(mixed $template): string
    {
        if (! is_string($template)) {
            $this->invalid('De inzetreferentie moet tekst zijn.');
        }

        $template = trim($template);
        if ($template === '') {
            $this->invalid('Vul een sjabloon voor de inzetreferentie in.');
        }
        if (mb_strlen($template) > self::MAX_TEMPLATE_LENGTH) {
            $this->invalid('Het sjabloon mag maximaal '.self::MAX_TEMPLATE_LENGTH.' tekens bevatten.');
        }
        if (preg_match('/[\\\\\/\x00-\x1F\x7F]/u', $template) === 1) {
            $this->invalid('Het sjabloon mag geen schuine strepen of besturingstekens bevatten.');
        }

        preg_match_all('/{{([a-z_]+)}}/', $template, $matches);
        $tokens = $matches[1] ?? [];
        foreach ($tokens as $token) {
            if (! in_array($token, self::ALLOWED_TOKENS, true)) {
                $this->invalid("Onbekende inzetreferentievariabele: {{$token}}.");
            }
        }
        if (! in_array('sequence', $tokens, true) && ! in_array('random', $tokens, true)) {
            $this->invalid('Gebruik {{sequence}} of {{random}} om iedere inzetreferentie uniek te houden.');
        }
        if (in_array('random', $tokens, true)
            && ! in_array('sequence', $tokens, true)
            && (! in_array('date', $tokens, true) || ! in_array('time', $tokens, true))) {
            $this->invalid('Gebruik {{random}} samen met {{date}} en {{time}}, of gebruik {{sequence}}.');
        }

        $literal = preg_replace('/{{(?:date|time|sequence|random)}}/', '', $template);
        if (! is_string($literal)
            || str_contains($literal, '{')
            || str_contains($literal, '}')) {
            $this->invalid('Gebruik alleen volledige variabelen zoals {{date}} of {{sequence}}.');
        }
        if (preg_match('/^[A-Za-z0-9._-]*$/', $literal) !== 1) {
            $this->invalid('Gebruik buiten variabelen alleen letters, cijfers, punten, liggende streepjes en koppeltekens.');
        }

        $preview = $this->render($template, Carbon::parse('2026-07-27 12:34:56', 'Europe/Amsterdam'), 1);
        if ($preview === null) {
            $this->invalid('Het sjabloon levert geen veilige inzetreferentie op.');
        }

        return $template;
    }

    /**
     * @return array{reference: string, sequence: int|null}
     */
    public function nextReference(bool $isTest = false): array
    {
        $referenceAt = now('Europe/Amsterdam');
        if ($isTest) {
            return [
                'reference' => 'TEST-'.$referenceAt->format('Ymd-His').'-'.$this->randomToken(),
                'sequence' => null,
            ];
        }
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Een inzetreferentie moet binnen een databasetransactie worden aangemaakt.');
        }

        $template = $this->configuredTemplate();

        do {
            $sequence = $this->sequences->next();
            $reference = $this->render($template, $referenceAt, $sequence)
                ?? $this->render(self::DEFAULT_TEMPLATE, $referenceAt, $sequence)
                ?? throw new RuntimeException('De inzetreferentie kon niet veilig worden opgebouwd.');
        } while ($this->sequences->referenceExists($reference));

        return [
            'reference' => $reference,
            'sequence' => $sequence,
        ];
    }

    private function configuredTemplate(): string
    {
        $configured = SystemSetting::value(self::SETTING_KEY, self::DEFAULT_TEMPLATE);

        try {
            return $this->validateTemplate($configured);
        } catch (ValidationException) {
            return self::DEFAULT_TEMPLATE;
        }
    }

    private function render(string $template, Carbon $referenceAt, int $sequence): ?string
    {
        $reference = strtr($template, [
            '{{date}}' => $referenceAt->format('Ymd'),
            '{{time}}' => $referenceAt->format('His'),
            '{{sequence}}' => str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            '{{random}}' => $this->randomToken(),
        ]);

        if ($reference === ''
            || strlen($reference) > self::MAX_REFERENCE_LENGTH
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $reference) !== 1) {
            return null;
        }

        return $reference;
    }

    private function randomToken(): string
    {
        return strtoupper(bin2hex(random_bytes(2)));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'settings.'.self::SETTING_KEY => [$message],
        ]);
    }
}
