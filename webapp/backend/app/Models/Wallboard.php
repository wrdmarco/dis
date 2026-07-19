<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Wallboard extends Model
{
    use UsesUlids;

    public const LAYOUT_FULLSCREEN_MAP = 'fullscreen_map';

    protected $fillable = [
        'name',
        'layout',
        'configuration',
        'config_version',
        'control_version',
        'manual_page_id',
        'manual_page_set_at',
        'rotation_started_at',
        'is_enabled',
        'paired_at',
        'last_seen_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'config_version' => 'integer',
            'control_version' => 'integer',
            'is_enabled' => 'boolean',
            'manual_page_set_at' => 'immutable_datetime',
            'rotation_started_at' => 'immutable_datetime',
            'paired_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WallboardSession::class);
    }

    public function pairingRequests(): HasMany
    {
        return $this->hasMany(WallboardPairingRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
