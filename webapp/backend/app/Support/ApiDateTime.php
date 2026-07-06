<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class ApiDateTime
{
    public static function dateTime(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value->format(DateTimeInterface::ATOM))
            ->setTimezone(self::timezone())
            ->format(DateTimeInterface::ATOM);
    }

    public static function now(): string
    {
        return CarbonImmutable::now(self::timezone())->format(DateTimeInterface::ATOM);
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone', 'Europe/Amsterdam');
    }
}
