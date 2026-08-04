<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class FormFieldValue
{
    public const TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';

    public static function normalizeScore(mixed $value, string $path): int
    {
        $score = match (true) {
            is_int($value) => $value,
            is_float($value) && floor($value) === $value => (int) $value,
            is_string($value) && preg_match('/^[1-5]$/D', trim($value)) === 1 => (int) trim($value),
            default => null,
        };

        if ($score === null || $score < FormFieldType::SCORE_MIN || $score > FormFieldType::SCORE_MAX) {
            throw ValidationException::withMessages([
                $path => [sprintf(
                    'Kies een score van %d tot en met %d.',
                    FormFieldType::SCORE_MIN,
                    FormFieldType::SCORE_MAX,
                )],
            ]);
        }

        return $score;
    }

    public static function normalizeDate(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([$path => ['Vul een geldige datum in.']]);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$path => ['Vul een geldige datum in.']]);
        }

        return $date->format('Y-m-d');
    }

    public static function normalizeDateTime(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([$path => ['Vul een geldig datum-tijdstip met tijdzone in.']]);
        }

        $candidateValue = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $parsed = null;
        foreach (['!Y-m-d\TH:iP', '!Y-m-d\TH:i:sP', '!Y-m-d\TH:i:s.vP', '!Y-m-d\TH:i:s.uP'] as $format) {
            $candidate = \DateTimeImmutable::createFromFormat($format, $candidateValue);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($candidate !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $parsed = $candidate;
                break;
            }
        }
        if ($parsed === null) {
            throw ValidationException::withMessages([$path => ['Vul een geldig datum-tijdstip met tijdzone in.']]);
        }

        return CarbonImmutable::instance($parsed)->utc()->toIso8601String();
    }

    public static function isValidDate(mixed $value): bool
    {
        try {
            self::normalizeDate($value, 'value');

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public static function isValidDateTime(mixed $value): bool
    {
        try {
            self::normalizeDateTime($value, 'value');

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public static function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $time = trim($value);

        return preg_match(self::TIME_PATTERN, $time) === 1 ? $time : null;
    }
}
