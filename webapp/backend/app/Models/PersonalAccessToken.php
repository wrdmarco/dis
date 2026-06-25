<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use UsesUlids;
}

