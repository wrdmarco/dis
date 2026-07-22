<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SpeechPreview extends Model
{
    use UsesUlids;

    protected $fillable = [
        'requested_by', 'phase', 'status', 'progress_percent', 'rendered_lines',
        'speech_manifest_build_id', 'speech_manifest_id', 'audio_asset_id', 'error_code',
        'expires_at', 'ready_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'rendered_lines' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(SpeechManifest::class, 'speech_manifest_id');
    }

    public function audioAsset(): BelongsTo
    {
        return $this->belongsTo(SpeechAudioAsset::class);
    }
}
