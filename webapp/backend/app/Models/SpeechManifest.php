<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SpeechManifest extends Model
{
    use UsesUlids;

    public $timestamps = false;

    protected $fillable = [
        'speech_manifest_build_id', 'dispatch_request_id', 'dispatch_recipient_id', 'phase', 'locale',
        'model_catalog_key', 'model_revision', 'model_weights_sha256', 'voice_profile_id',
        'voice_consent_version', 'voice_design_revision', 'speed', 'template_checksum', 'context_hmac', 'manifest_sha256',
        'audio_asset_id', 'segment_count', 'duration_ms', 'expires_at', 'sealed_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'speed' => 'decimal:2',
            'voice_consent_version' => 'integer',
            'segment_count' => 'integer',
            'duration_ms' => 'integer',
            'expires_at' => 'immutable_datetime',
            'sealed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function dispatchRequest(): BelongsTo
    {
        return $this->belongsTo(DispatchRequest::class);
    }

    public function dispatchRecipient(): BelongsTo
    {
        return $this->belongsTo(DispatchRecipient::class);
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(SpeechManifestBuild::class, 'speech_manifest_build_id');
    }

    public function voiceProfile(): BelongsTo
    {
        return $this->belongsTo(SpeechVoiceProfile::class);
    }

    public function audioAsset(): BelongsTo
    {
        return $this->belongsTo(SpeechAudioAsset::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(SpeechManifestSegment::class)->orderBy('sequence');
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new \LogicException('Sealed speech manifests are immutable.'));
        self::deleting(static fn (): never => throw new \LogicException('Sealed speech manifests are immutable.'));
    }
}
