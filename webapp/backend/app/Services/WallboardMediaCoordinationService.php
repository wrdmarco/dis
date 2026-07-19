<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class WallboardMediaCoordinationService
{
    public const SCOPE = 'library';

    /**
     * Take the shared database row lock inside the caller's transaction before
     * locking a wallboard playlist or media playlist. This serializes changes
     * to the JSON configuration and its relational usage projection.
     */
    public function lock(): void
    {
        $scope = DB::table('wallboard_media_coordination_locks')
            ->where('scope', self::SCOPE)
            ->lockForUpdate()
            ->value('scope');
        if ($scope !== self::SCOPE) {
            throw new \LogicException('Wallboard media coordination lock is missing.');
        }
    }
}
