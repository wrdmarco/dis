<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SpeechManifestBuild extends Model
{
    use UsesUlids;

    public const STATUS_FAILED = 'failed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_READY = 'ready';

    protected $fillable = [
        'dispatch_request_id', 'dispatch_recipient_id', 'phase', 'locale', 'model_installation_id',
        'voice_profile_id', 'voice_design_revision', 'audio_recipe_revision', 'speed', 'template_checksum', 'context_hmac',
        'source_fingerprint_hmac',
        'rendered_lines', 'status', 'error_code', 'progress_percent', 'release_deadline',
        'finished_at', 'failed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'speed' => 'decimal:2',
            'rendered_lines' => 'encrypted:array',
            'progress_percent' => 'integer',
            'release_deadline' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
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

    public function modelInstallation(): BelongsTo
    {
        return $this->belongsTo(SpeechModelInstallation::class);
    }

    public function voiceProfile(): BelongsTo
    {
        return $this->belongsTo(SpeechVoiceProfile::class);
    }
}
