<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

trait UsesUlids
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}

