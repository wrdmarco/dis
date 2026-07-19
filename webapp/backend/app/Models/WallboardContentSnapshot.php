<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WallboardContentSnapshot extends Model
{
    public const KIND_NEWS = 'news';

    public const KIND_TICKER = 'ticker';

    /** @var list<string> */
    public const KINDS = [self::KIND_NEWS, self::KIND_TICKER];

    protected $table = 'wallboard_content_snapshots';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'revision' => 'integer',
            'checked_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
