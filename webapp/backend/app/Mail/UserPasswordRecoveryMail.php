<?php

namespace App\Mail;

use App\Mail\Concerns\BuildsSimpleHtmlMail;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class UserPasswordRecoveryMail extends Mailable
{
    use BuildsSimpleHtmlMail;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly User $user, public readonly string $recoveryUrl) {}

    public function build(): self
    {
        $appName = SystemSetting::string('app.brand_name', 'D.I.S') ?? 'D.I.S';
        $tenantName = SystemSetting::string('mobile.tenant_name', 'Nationaal Droneteam') ?? 'Nationaal Droneteam';
        $subject = "Wachtwoord herstellen voor {$appName}";
        $body = "Beste {$this->user->name},\n\nEen beheerder heeft een wachtwoordherstel voor je account gestart. Stel via onderstaande eenmalige link zelf een nieuw wachtwoord in. Heb je dit niet verwacht, neem dan contact op met je beheerder.";
        $html = $this->simpleHtmlBody($appName, $tenantName, $subject, $body, $this->recoveryUrl, 'Nieuw wachtwoord instellen');

        return $this->subject($subject)->html($html);
    }
}
