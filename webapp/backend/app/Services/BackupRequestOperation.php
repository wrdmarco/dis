<?php

namespace App\Services;

enum BackupRequestOperation: string
{
    case Create = 'create';
    case Verify = 'verify';
    case Probe = 'probe';
}
