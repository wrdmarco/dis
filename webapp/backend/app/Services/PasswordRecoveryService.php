<?php

namespace App\Services;

use App\Jobs\SendPasswordRecoveryLink;
use App\Mail\UserPasswordRecoveryMail;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Throwable;

final class PasswordRecoveryService
{
    public function queue(string $email): void
    {
        SendPasswordRecoveryLink::dispatch(mb_strtolower(trim($email)));
    }

    public function deliver(User $user): void
    {
        $token = Password::broker()->createToken($user);
        $publicUrl = rtrim(SystemSetting::string('app.public_url', config('app.url', '')) ?? '', '/');
        $recoveryUrl = $publicUrl.'/register#mode=recovery&email='.rawurlencode($user->email).'&token='.rawurlencode($token);

        try {
            Mail::to($user->email)->send(new UserPasswordRecoveryMail($user, $recoveryUrl));
        } catch (Throwable $exception) {
            Password::broker()->deleteToken($user);

            throw $exception;
        }
    }
}
