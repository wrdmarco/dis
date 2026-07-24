<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SpeechPreparedPhrase extends Model
{
    use UsesUlids;

    public const KINDS = ['residence', 'province', 'postcode', 'fixed_phrase'];

    public const STATUSES = ['queued', 'processing', 'ready', 'failed'];

    protected $fillable = [
        'kind',
        'identity_hmac',
        'display_text',
        'status',
        'progress_percent',
        'error_code',
        'cache_entry_id',
        'runtime_fingerprint_hmac',
        'created_by',
        'prepared_at',
    ];

    protected function casts(): array
    {
        return [
            'display_text' => 'encrypted',
            'progress_percent' => 'integer',
            'prepared_at' => 'immutable_datetime',
        ];
    }

    public function cacheEntry(): BelongsTo
    {
        return $this->belongsTo(SpeechCacheEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
