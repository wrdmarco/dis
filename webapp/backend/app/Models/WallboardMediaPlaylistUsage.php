<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WallboardMediaPlaylistUsage extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['wallboard_playlist_id', 'page_id', 'media_playlist_id'];

    public function wallboardPlaylist(): BelongsTo
    {
        return $this->belongsTo(WallboardPlaylist::class, 'wallboard_playlist_id');
    }

    public function mediaPlaylist(): BelongsTo
    {
        return $this->belongsTo(WallboardMediaPlaylist::class, 'media_playlist_id');
    }
}
