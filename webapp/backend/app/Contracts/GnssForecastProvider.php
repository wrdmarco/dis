<?php

namespace App\Contracts;

interface GnssForecastProvider
{
    /**
     * Return a fail-closed GNSS availability plan for every resolved location.
     * Counts describe predicted open-sky satellite geometry from broadcast
     * ephemerides; they are not receiver measurements or a reported fix.
     *
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    public function forResolution(array $resolution): array;
}
