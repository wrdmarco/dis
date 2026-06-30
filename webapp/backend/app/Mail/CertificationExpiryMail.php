<?php

namespace App\Mail;

use App\Mail\Concerns\BuildsSimpleHtmlMail;
use App\Models\SystemSetting;
use App\Models\UserCertification;
use App\Services\MailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class CertificationExpiryMail extends Mailable
{
    use BuildsSimpleHtmlMail;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly UserCertification $userCertification,
        public readonly int $daysUntilExpiry,
        public readonly string $downloadUrl,
    ) {}

    public function build(): self
    {
        $certificationName = $this->userCertification->certification?->name ?? 'Certificaat';
        $expiresAt = $this->userCertification->expires_at?->format('d-m-Y') ?? '-';
        $isExpired = $this->daysUntilExpiry <= 0;
        $appName = SystemSetting::string('app.brand_name', 'D.I.S') ?? 'D.I.S';
        $tenantName = SystemSetting::string('mobile.tenant_name', 'Nationaal Droneteam') ?? 'Nationaal Droneteam';
        $subjectTemplate = SystemSetting::string(
            'mail.template.certification_expiry_subject',
            '{{certification_name}} - {{status_text}}',
        ) ?? '';
        $bodyTemplate = SystemSetting::string(
            'mail.template.certification_expiry_body',
            "Beste {{name}},\n\nJe certificaat {{certification_name}} {{expiry_status}}.\n\nCertificaatnummer: {{certificate_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk je certificaat bij in de app zodra de verlenging rond is. Zonder geldig vereist certificaat kun je niet meegenomen worden in alarmeringen waarvoor dit certificaat verplicht is.",
        ) ?? '';
        $tokens = [
            'name' => $this->userCertification->user?->name ?? 'gebruiker',
            'email' => $this->userCertification->user?->email ?? '',
            'certification_name' => $certificationName,
            'certificate_number' => $this->userCertification->certificate_number ?? '-',
            'expires_at' => $expiresAt,
            'days_until_expiry' => $this->daysUntilExpiry,
            'expiry_status' => $isExpired ? 'is verlopen' : 'verloopt binnenkort',
            'status_text' => $isExpired ? 'Verlopen' : $this->daysUntilExpiry.' dagen resterend',
            'download_url' => $this->downloadUrl,
            'app_name' => $appName,
            'tenant_name' => $tenantName,
        ];
        $renderer = app(MailTemplateRenderer::class);
        $subject = $renderer->render($subjectTemplate, $tokens);
        $body = $renderer->render($bodyTemplate, $tokens);

        return $this
            ->subject($subject)
            ->html($this->simpleHtmlBody($appName, $tenantName, $subject, $body));
    }
}
