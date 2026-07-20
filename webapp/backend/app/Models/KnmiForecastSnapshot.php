<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;

final class KnmiForecastSnapshot extends Model
{
    use UsesUlids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    public const ACTIVE_KEY = 'knmi-harmonie-p1';

    protected $fillable = [
        'dataset',
        'dataset_version',
        'source_filename',
        'source_size_bytes',
        'source_sha256',
        'model_run_at',
        'forecast_start_at',
        'forecast_end_at',
        'member_count',
        'release_directory',
        'manifest',
        'active_key',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'source_size_bytes' => 'integer',
            'model_run_at' => 'immutable_datetime',
            'forecast_start_at' => 'immutable_datetime',
            'forecast_end_at' => 'immutable_datetime',
            'member_count' => 'integer',
            'manifest' => 'array',
            'activated_at' => 'immutable_datetime',
        ];
    }
}
