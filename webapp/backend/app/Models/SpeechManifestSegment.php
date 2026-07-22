<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SpeechManifestSegment extends Model
{
    use UsesUlids;

    public $timestamps = false;

    protected $fillable = [
        'speech_manifest_id', 'sequence', 'semantic_key', 'text', 'text_hmac', 'cache_key',
        'audio_asset_id', 'duration_ms', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'duration_ms' => 'integer',
            'text' => 'encrypted',
            'created_at' => 'immutable_datetime',
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
