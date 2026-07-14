<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthenticationStateRevocationService;
use Illuminate\Console\Command;

final class RevokeAllAuthenticationState extends Command
{
    protected $signature = 'dis:revoke-all-authentication-state {--reason=security-operation}';

    protected $description = 'Revoke every browser, API, pairing and push authentication state.';

    public function handle(
        AuthenticationStateRevocationService $revocation,
        AuditService $auditService,
    ): int {
        $reason = trim((string) $this->option('reason'));
        if (preg_match('/^[a-z0-9._-]{1,64}$/', $reason) !== 1) {
            $this->error('Invalid revocation reason.');

            return self::INVALID;
        }

        $counts = $revocation->revokeAll();
        $auditService->record('auth.all_state_revoked', User::class, null, [
            'reason' => $reason,
            'counts' => $counts,
        ]);
        $this->info('All authentication state was revoked.');

        return self::SUCCESS;
    }
}
