<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;

final class SpeechModelInstallation extends Model
{
    use UsesUlids;

    protected $fillable = [
        'catalog_key', 'revision', 'weights_sha256', 'status', 'progress_percent', 'error_code',
        'requested_by', 'license_confirmed_at', 'installed_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'license_confirmed_at' => 'immutable_datetime',
            'installed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
