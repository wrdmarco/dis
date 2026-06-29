<?php

namespace App\Mail;

use App\Models\Asset;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class AssetExpiryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Asset $asset,
        public readonly User $user,
        public readonly int $daysUntilExpiry,
        public readonly string $downloadUrl,
    ) {}

    public function build(): self
    {
        $expiresAt = $this->asset->maintenance_due_at?->format('d-m-Y') ?? '-';
        $isExpired = $this->daysUntilExpiry < 0;
        $appName = SystemSetting::string('app.brand_name', 'D.I.S') ?? 'D.I.S';
        $tenantName = SystemSetting::string('mobile.tenant_name', 'Nationaal Droneteam') ?? 'Nationaal Droneteam';
        $subjectTemplate = SystemSetting::string('mail.template.asset_expiry_subject', '{{asset_name}} - {{status_text}}') ?? '';
        $bodyTemplate = SystemSetting::string(
            'mail.template.asset_expiry_body',
            "Beste {{name}},\n\nDe verloopdatum of onderhoudsdatum van asset {{asset_name}} {{expiry_status}}.\n\nAsset tag: {{asset_tag}}\nSerienummer: {{serial_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk de assetgegevens bij zodra dit is afgehandeld.\n\nApp downloadpagina:\n{{download_url}}",
        ) ?? '';
        $tokens = [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'asset_name' => $this->asset->name,
            'asset_tag' => $this->asset->asset_tag,
            'asset_type' => $this->asset->type,
            'serial_number' => $this->asset->serial_number ?? '-',
            'expires_at' => $expiresAt,
            'days_until_expiry' => $this->daysUntilExpiry,
            'expiry_status' => $isExpired ? 'is verlopen' : 'verloopt binnenkort',
            'status_text' => $isExpired ? 'Verlopen' : $this->daysUntilExpiry.' dagen resterend',
            'download_url' => $this->downloadUrl,
            'app_name' => $appName,
            'tenant_name' => $tenantName,
        ];
        $renderer = app(MailTemplateRenderer::class);

        return $this
            ->subject($renderer->render($subjectTemplate, $tokens))
            ->view('mail.asset-expiry', [
                'tenantName' => $tenantName,
                'mailTitle' => $isExpired ? 'Asset verlopen' : 'Asset verloopt binnenkort',
                'body' => $renderer->render($bodyTemplate, $tokens),
                'downloadUrl' => $this->downloadUrl,
            ]);
    }
}
