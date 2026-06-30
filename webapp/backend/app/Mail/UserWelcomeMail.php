<?php

namespace App\Mail;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MailTemplateRenderer;
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
        $renderer = app(MailTemplateRenderer::class);
        $appName = SystemSetting::string('app.brand_name', 'D.I.S') ?? 'D.I.S';
        $tenantName = SystemSetting::string('mobile.tenant_name', 'Nationaal Droneteam') ?? 'Nationaal Droneteam';
        $subjectTemplate = SystemSetting::string('mail.template.welcome_subject', 'Welkom bij {{app_name}}') ?? 'Welkom bij {{app_name}}';
        $bodyTemplate = SystemSetting::string(
            'mail.template.welcome_body',
            "Beste {{name}},\n\nEr is een account voor je aangemaakt in {{app_name}}. Rond je registratie af via onderstaande link:\n\n{{registration_url}}",
        ) ?? '';
        $adminAppNote = $this->adminAppAllowed
            ? 'Omdat je adminrechten hebt, toont de wizard ook de installatie-informatie voor de admin app.'
            : '';
        $tokens = [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'registration_url' => $this->registrationUrl,
            'app_name' => $appName,
            'tenant_name' => $tenantName,
            'admin_app_note' => $adminAppNote,
        ];

        $subject = $renderer->render($subjectTemplate, $tokens);
        $body = $renderer->render($bodyTemplate, $tokens);
        $html = $this->htmlBody($appName, $tenantName, $subject, $body);

        return $this
            ->subject($subject)
            ->html($html);
    }

    private function htmlBody(string $appName, string $tenantName, string $subject, string $body): string
    {
        $lines = array_filter([
            '<!doctype html>',
            '<html lang="nl">',
            '<head><meta charset="utf-8"><title>'.e($subject).'</title></head>',
            '<body style="font-family:Arial,sans-serif;line-height:1.5;color:#111827;background:#f8fafc;padding:24px;">',
            '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;">',
            '<p style="margin:0 0 4px;color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.08em;">'.e($tenantName).'</p>',
            '<h1 style="margin:0 0 20px;font-size:22px;color:#111827;">'.e($appName).'</h1>',
            '<div style="white-space:pre-line;font-size:15px;">'.e($body).'</div>',
            '<p style="margin-top:24px;"><a href="'.e($this->registrationUrl).'" style="display:inline-block;background:#0284c7;color:#ffffff;text-decoration:none;border-radius:6px;padding:10px 16px;">Registratie afronden</a></p>',
            '</div>',
            '</body>',
            '</html>',
        ]);

        return implode('', $lines);
    }
}
