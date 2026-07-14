<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PasswordRecoveryService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendPasswordRecoveryLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $email) {}

    public function handle(PasswordRecoveryService $passwordRecovery): void
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($this->email))])
            ->first();

        if ($user === null || $user->account_status !== 'active') {
            return;
        }

        $passwordRecovery->deliver($user);
    }
}
