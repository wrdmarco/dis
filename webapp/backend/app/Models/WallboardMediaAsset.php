<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class WallboardMediaAsset extends Model
{
    use SoftDeletes;
    use UsesUlids;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'id',
        'folder_id',
        'display_name',
        'original_name',
        'storage_path',
        'sha256',
        'mime_type',
        'byte_size',
        'width',
        'height',
        'status',
        'version',
        'created_by',
        'updated_by',
    ];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'version' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(WallboardMediaFolder::class, 'folder_id');
    }

    public function playlistItems(): HasMany
    {
        return $this->hasMany(WallboardMediaPlaylistItem::class, 'media_asset_id');
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
