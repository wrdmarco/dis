<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;

final class SpeechAudioAsset extends Model
{
    use UsesUlids;

    protected $fillable = ['content_sha256', 'storage_path', 'mime_type', 'byte_size', 'duration_ms'];

    protected function casts(): array
    {
        return ['byte_size' => 'integer', 'duration_ms' => 'integer'];
    }
}
