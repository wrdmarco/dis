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

        return self::localWallClock($value)?->format(DateTimeInterface::ATOM);
    }

    /**
     * PostgreSQL historically stored DIS application wall-clock values in a
     * timestamptz column through a UTC session. Preserve that established
     * convention when comparing or presenting those database values.
     */
    public static function localWallClock(?DateTimeInterface $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            $value->format('Y-m-d H:i:s.u'),
            self::timezone(),
        );
    }

    /**
     * Convert the persisted DIS wall-clock fields to a fixed-offset value for
     * comparisons. Both the database value and the comparison boundary must
     * pass through this method. UTC is deliberately used only as a neutral
     * carrier for the clock fields; the result is not the original instant.
     * This also avoids choosing the wrong offset during the repeated DST hour.
     */
    public static function comparableWallClock(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            $value->format('Y-m-d H:i:s.u'),
            'UTC',
        );
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
