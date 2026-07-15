<?php

namespace App\Services;

enum BackupReportOrigin: string
{
    case Automatic = 'Automatische backup';
    case Manual = 'Handmatige backup';

    public function subject(bool $successful): string
    {
        return $this->value.($successful ? ' geslaagd' : ' mislukt');
    }
}
