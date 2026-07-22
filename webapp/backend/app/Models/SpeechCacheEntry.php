<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SpeechCacheEntry extends Model
{
    use UsesUlids;

    protected $fillable = [
        'cache_key', 'category', 'audio_asset_id', 'voice_profile_id', 'semantic_hmac', 'status', 'error_code',
        'hit_count', 'last_used_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'hit_count' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function audioAsset(): BelongsTo
    {
        return $this->belongsTo(SpeechAudioAsset::class);
    }

    public function voiceProfile(): BelongsTo
    {
        return $this->belongsTo(SpeechVoiceProfile::class);
    }
}
