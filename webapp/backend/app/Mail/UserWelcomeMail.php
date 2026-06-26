<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class UserWelcomeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $registrationUrl,
        public readonly bool $adminAppAllowed,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Welkom bij D.I.S')
            ->view('mail.user-welcome');
    }
}
