<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WallboardMediaAssetUsage extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['wallboard_playlist_id', 'page_id', 'media_asset_id'];

    public function wallboardPlaylist(): BelongsTo
    {
        return $this->belongsTo(WallboardPlaylist::class, 'wallboard_playlist_id');
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(WallboardMediaAsset::class, 'media_asset_id');
    }
}
