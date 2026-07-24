<?php

namespace App\Services;

enum BackupRequestOperation: string
{
    case Create = 'create';
    case Prune = 'prune';
    case Verify = 'verify';
    case Probe = 'probe';
}
