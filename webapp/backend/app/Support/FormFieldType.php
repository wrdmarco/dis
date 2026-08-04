<?php

namespace App\Support;

final class FormFieldType
{
    /** @var list<string> */
    public const ALL = [
        'section',
        'text',
        'textarea',
        'address',
        'number',
        'phone',
        'flight_time',
        'select',
        'radio',
        'checkbox',
        'date',
        'datetime',
        'score',
    ];

    public const SCORE_MIN = 1;

    public const SCORE_MAX = 5;

    /** @var array<int, string> */
    public const SCORE_LABELS = [
        1 => 'Niet goed',
        2 => 'Matig',
        3 => 'Neutraal',
        4 => 'Goed',
        5 => 'Zeer goed',
    ];

    public static function scoreLabel(int $score): ?string
    {
        return self::SCORE_LABELS[$score] ?? null;
    }

    public static function scoreDisplay(int $score): ?string
    {
        $label = self::scoreLabel($score);

        return $label === null ? null : sprintf('%d/%d – %s', $score, self::SCORE_MAX, $label);
    }
}
