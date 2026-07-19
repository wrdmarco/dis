<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WallboardMediaPlaylist extends Model
{
    use UsesUlids;

    protected $fillable = ['name', 'version', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(WallboardMediaPlaylistItem::class, 'media_playlist_id')
            ->orderBy('position');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(WallboardMediaPlaylistUsage::class, 'media_playlist_id');
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
