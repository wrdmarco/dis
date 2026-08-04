<?php

namespace App\Repositories;

use App\Models\WebLoginApproval;
use App\Models\WebLoginApprovalRecipient;

/**
 * @extends BaseRepository<WebLoginApproval>
 */
final class WebLoginApprovalRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return WebLoginApproval::class;
    }

    public function findForBrowserSession(string $sessionHash, bool $lockForUpdate = false): ?WebLoginApproval
    {
        $query = WebLoginApproval::query()
            ->where('browser_session_hash', $sessionHash)
            ->with('recipients');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function lockForTargetedDevice(
        string $approvalId,
        string $userId,
        string $fcmTokenId,
        string $personalAccessTokenId,
    ): ?WebLoginApproval {
        return WebLoginApproval::query()
            ->whereKey($approvalId)
            ->where('user_id', $userId)
            ->whereHas('recipients', fn ($recipients) => $recipients
                ->where('fcm_token_id', $fcmTokenId)
                ->where('personal_access_token_id', $personalAccessTokenId))
            ->with('recipients')
            ->lockForUpdate()
            ->first();
    }

    public function recipient(string $recipientId): ?WebLoginApprovalRecipient
    {
        return WebLoginApprovalRecipient::query()->find($recipientId);
    }
}
