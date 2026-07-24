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
        'display_text', 'locale', 'model_catalog_key', 'model_revision', 'voice_design_revision',
        'audio_recipe_revision', 'speed', 'synthesis_duration_ms', 'hit_count', 'last_used_at', 'expires_at',
        'is_pinned', 'pinned_at',
    ];

    protected function casts(): array
    {
        return [
            'display_text' => 'encrypted',
            'speed' => 'decimal:2',
            'synthesis_duration_ms' => 'integer',
            'hit_count' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'is_pinned' => 'boolean',
            'pinned_at' => 'immutable_datetime',
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
