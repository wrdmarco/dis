<?php

namespace App\Mail;

use App\Mail\Concerns\BuildsSimpleHtmlMail;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class UserWelcomeMail extends Mailable
{
    use BuildsSimpleHtmlMail;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $registrationUrl,
        public readonly bool $adminAppAllowed,
    ) {}

    public function build(): self
    {
        $renderer = app(MailTemplateRenderer::class);
        $appName = SystemSetting::string('app.brand_name', 'D.I.S') ?? 'D.I.S';
        $tenantName = SystemSetting::string('mobile.tenant_name', 'Nationaal Droneteam') ?? 'Nationaal Droneteam';
        $subjectTemplate = SystemSetting::string('mail.template.welcome_subject', 'Welkom bij {{app_name}}') ?? 'Welkom bij {{app_name}}';
        $bodyTemplate = SystemSetting::string(
            'mail.template.welcome_body',
            "Beste {{name}},\n\nEr is een account voor je aangemaakt in {{app_name}}. Rond je registratie af via onderstaande link:\n\n{{registration_url}}",
        ) ?? '';
        $webAdminNote = $this->adminAppAllowed
            ? 'Omdat je beheerrechten hebt, kun je D.I.S. na registratie rechtstreeks in de beveiligde webapp beheren.'
            : '';
        $tokens = [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'registration_url' => $this->registrationUrl,
            'app_name' => $appName,
            'tenant_name' => $tenantName,
            'web_admin_note' => $webAdminNote,
            // Backwards-compatible alias for existing customized templates.
            'admin_app_note' => $webAdminNote,
        ];

        $subject = $renderer->render($subjectTemplate, $tokens);
        $body = $renderer->render($bodyTemplate, $tokens);
        $html = $this->simpleHtmlBody($appName, $tenantName, $subject, $body, $this->registrationUrl, 'Registratie afronden');

        return $this
            ->subject($subject)
            ->html($html);
    }
}
