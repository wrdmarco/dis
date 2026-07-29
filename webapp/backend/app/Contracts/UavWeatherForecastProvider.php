<?php

namespace App\Contracts;

interface UavWeatherForecastProvider
{
    /**
     * Return one validated, server-side weather reading for the requested
     * resolution. Implementations aggregate every required location before
     * returning a complete reading.
     *
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    public function forResolution(array $resolution): array;
}
