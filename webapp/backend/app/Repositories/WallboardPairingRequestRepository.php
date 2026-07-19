<?php

namespace App\Repositories;

use App\Models\WallboardPairingRequest;

/**
 * @extends BaseRepository<WallboardPairingRequest>
 */
final class WallboardPairingRequestRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return WallboardPairingRequest::class;
    }

    public function codeHashExists(string $codeHash): bool
    {
        return WallboardPairingRequest::query()
            ->where('code_hash', $codeHash)
            ->exists();
    }

    public function lockById(string $id): ?WallboardPairingRequest
    {
        return WallboardPairingRequest::query()
            ->with(['wallboard', 'approver', 'wallboardSession'])
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
    }

    public function lockByCodeHash(string $codeHash): ?WallboardPairingRequest
    {
        return WallboardPairingRequest::query()
            ->where('code_hash', $codeHash)
            ->lockForUpdate()
            ->first();
    }
}
