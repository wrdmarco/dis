<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceDefaultTemplate(
            'mail.template.certification_expiry_body',
            "Beste {{name}},\n\nJe certificaat {{certification_name}} {{expiry_status}}.\n\nCertificaatnummer: {{certificate_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk je certificaat bij in de app zodra de verlenging rond is. Zonder geldig vereist certificaat kun je niet meegenomen worden in alarmeringen waarvoor dit certificaat verplicht is.\n\nApp downloadpagina:\n{{download_url}}",
            "Beste {{name}},\n\nJe certificaat {{certification_name}} {{expiry_status}}.\n\nCertificaatnummer: {{certificate_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk je certificaat bij in de app zodra de verlenging rond is. Zonder geldig vereist certificaat kun je niet meegenomen worden in alarmeringen waarvoor dit certificaat verplicht is.",
        );

        $this->replaceDefaultTemplate(
            'mail.template.asset_expiry_body',
            "Beste {{name}},\n\nDe verloopdatum of onderhoudsdatum van asset {{asset_name}} {{expiry_status}}.\n\nAsset tag: {{asset_tag}}\nSerienummer: {{serial_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk de assetgegevens bij zodra dit is afgehandeld.\n\nApp downloadpagina:\n{{download_url}}",
            "Beste {{name}},\n\nDe verloopdatum of onderhoudsdatum van asset {{asset_name}} {{expiry_status}}.\n\nAsset tag: {{asset_tag}}\nSerienummer: {{serial_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk de assetgegevens bij zodra dit is afgehandeld.",
        );
    }

    public function down(): void
    {
        $this->replaceDefaultTemplate(
            'mail.template.certification_expiry_body',
            "Beste {{name}},\n\nJe certificaat {{certification_name}} {{expiry_status}}.\n\nCertificaatnummer: {{certificate_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk je certificaat bij in de app zodra de verlenging rond is. Zonder geldig vereist certificaat kun je niet meegenomen worden in alarmeringen waarvoor dit certificaat verplicht is.",
            "Beste {{name}},\n\nJe certificaat {{certification_name}} {{expiry_status}}.\n\nCertificaatnummer: {{certificate_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk je certificaat bij in de app zodra de verlenging rond is. Zonder geldig vereist certificaat kun je niet meegenomen worden in alarmeringen waarvoor dit certificaat verplicht is.\n\nApp downloadpagina:\n{{download_url}}",
        );

        $this->replaceDefaultTemplate(
            'mail.template.asset_expiry_body',
            "Beste {{name}},\n\nDe verloopdatum of onderhoudsdatum van asset {{asset_name}} {{expiry_status}}.\n\nAsset tag: {{asset_tag}}\nSerienummer: {{serial_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk de assetgegevens bij zodra dit is afgehandeld.",
            "Beste {{name}},\n\nDe verloopdatum of onderhoudsdatum van asset {{asset_name}} {{expiry_status}}.\n\nAsset tag: {{asset_tag}}\nSerienummer: {{serial_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk de assetgegevens bij zodra dit is afgehandeld.\n\nApp downloadpagina:\n{{download_url}}",
        );
    }

    private function replaceDefaultTemplate(string $key, string $from, string $to): void
    {
        DB::table('system_settings')
            ->where('key', $key)
            ->where('value', json_encode($from, JSON_THROW_ON_ERROR))
            ->update([
                'value' => json_encode($to, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
