<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WallboardMediaPlaylistItem extends Model
{
    use UsesUlids;

    protected $fillable = ['media_playlist_id', 'media_asset_id', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(WallboardMediaPlaylist::class, 'media_playlist_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(WallboardMediaAsset::class, 'media_asset_id');
    }
}
