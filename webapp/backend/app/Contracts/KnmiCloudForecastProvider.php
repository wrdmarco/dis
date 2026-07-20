<?php

namespace App\Contracts;

interface KnmiCloudForecastProvider
{
    /**
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    public function forResolution(array $resolution): array;
}
