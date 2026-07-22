<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SpeechVoiceProfile extends Model
{
    use SoftDeletes;
    use UsesUlids;

    protected $fillable = [
        'name', 'locale', 'transcript', 'consent_statement', 'consent_recorded_at',
        'sample_storage_path', 'sample_sha256', 'sample_byte_size', 'reference_duration_ms',
        'consent_version', 'status', 'error_code', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transcript' => 'encrypted',
            'consent_statement' => 'encrypted',
            'consent_recorded_at' => 'immutable_datetime',
            'sample_byte_size' => 'integer',
            'reference_duration_ms' => 'integer',
            'consent_version' => 'integer',
        ];
    }
}
