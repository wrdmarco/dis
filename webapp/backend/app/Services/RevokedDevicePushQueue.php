<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use Throwable;

final class RevokedDevicePushQueue
{
    public function enqueue(FcmToken $token, string $title, string $body): bool
    {
        $revocationGeneration = trim((string) $token->revocation_generation);
        if ($token->revoked_at === null || $token->is_active || $revocationGeneration === '') {
            return false;
        }

        try {
            // The encrypted job carries only the database identifier and the
            // fixed revocation copy. The provider token is resolved from the
            // retained revoked row by the dedicated push worker. Its current
            // personal-access-token binding is added only at provider send
            // time; the row remains available for outbox history and normal
            // retention pruning.
            SendFcmNotification::dispatch(
                (string) $token->id,
                'session_revoked',
                $title,
                $body,
                ['type' => 'session_revoked'],
                null,
                null,
                $revocationGeneration,
            )->onConnection('push')->onQueue('push');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
