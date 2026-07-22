<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;

final class SpeechCacheJob extends Model
{
    use UsesUlids;

    protected $fillable = ['scope', 'status', 'progress_percent', 'error_code', 'requested_by', 'finished_at'];

    protected function casts(): array
    {
        return ['progress_percent' => 'integer', 'finished_at' => 'immutable_datetime'];
    }
}
