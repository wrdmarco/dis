<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WallboardPlaylist extends Model
{
    use UsesUlids;

    public const DATA_MODE_LIVE = 'live';

    public const DATA_MODE_DEMO = 'demo';

    /** @var list<string> */
    public const DATA_MODES = [self::DATA_MODE_LIVE, self::DATA_MODE_DEMO];

    public const PURPOSE_NORMAL = 'normal';

    public const PURPOSE_ALARM = 'alarm';

    /** @var list<string> */
    public const PURPOSES = [self::PURPOSE_NORMAL, self::PURPOSE_ALARM];

    protected $fillable = [
        'name',
        'data_mode',
        'purpose',
        'configuration',
        'version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'data_mode' => 'string',
            'purpose' => 'string',
            'configuration' => 'array',
            'version' => 'integer',
        ];
    }

    public function isDemo(): bool
    {
        return $this->data_mode === self::DATA_MODE_DEMO;
    }

    public function normalizedPurpose(): string
    {
        return in_array($this->purpose, self::PURPOSES, true)
            ? (string) $this->purpose
            : self::PURPOSE_NORMAL;
    }

    public function isAlarmPurpose(): bool
    {
        return $this->normalizedPurpose() === self::PURPOSE_ALARM;
    }

    public function wallboards(): HasMany
    {
        return $this->hasMany(Wallboard::class, 'playlist_id');
    }

    public function mediaAssetUsages(): HasMany
    {
        return $this->hasMany(WallboardMediaAssetUsage::class, 'wallboard_playlist_id');
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
