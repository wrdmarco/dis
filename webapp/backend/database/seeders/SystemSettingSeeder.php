<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SystemSettingSeeder extends Seeder
{
    /**
     * @var array<string, array{value: mixed, is_sensitive: bool}>
     */
    private array $settings = [
        'dispatch.response_timeout_seconds' => ['value' => 300, 'is_sensitive' => false],
        'dispatch.escalation_enabled' => ['value' => true, 'is_sensitive' => false],
        'incident.timeline.app_visible_types' => ['value' => ['status', 'dispatch', 'dispatch_response', 'dispatch_message', 'operator_status'], 'is_sensitive' => false],
        'operational_map.command_centers' => ['value' => [], 'is_sensitive' => false],
        'devices.heartbeat_interval_minutes' => ['value' => 15, 'is_sensitive' => false],
        'test_alert.schedule_enabled' => ['value' => false, 'is_sensitive' => false],
        'test_alert.schedule_day_of_week' => ['value' => 1, 'is_sensitive' => false],
        'test_alert.schedule_time' => ['value' => '09:00', 'is_sensitive' => false],
        'test_alert.message' => ['value' => 'Dit is het wekelijkse proefalarm.', 'is_sensitive' => false],
        'test_alert.schedule_last_run_at' => ['value' => null, 'is_sensitive' => false],
        'push.availability_requires_push' => ['value' => true, 'is_sensitive' => false],
        'backup.target' => ['value' => 'local', 'is_sensitive' => false],
        'backup.local_path' => ['value' => '/opt/dis-data/backup', 'is_sensitive' => false],
        'backup.samba.server' => ['value' => '', 'is_sensitive' => false],
        'backup.samba.share_name' => ['value' => '', 'is_sensitive' => false],
        'backup.samba.share' => ['value' => '', 'is_sensitive' => false],
        'backup.samba.mount' => ['value' => '/mnt/dis-backup', 'is_sensitive' => false],
        'backup.samba.username' => ['value' => '', 'is_sensitive' => false],
        'backup.samba.password' => ['value' => '', 'is_sensitive' => true],
        'backup.samba.domain' => ['value' => '', 'is_sensitive' => false],
        'backup.samba.version' => ['value' => '3.1.1', 'is_sensitive' => false],
        'backup.auto.enabled' => ['value' => false, 'is_sensitive' => false],
        'backup.auto.frequency' => ['value' => 'daily', 'is_sensitive' => false],
        'backup.auto.day_of_week' => ['value' => 1, 'is_sensitive' => false],
        'backup.auto.time' => ['value' => '02:15', 'is_sensitive' => false],
        'backup.retention_count' => ['value' => 7, 'is_sensitive' => false],
        'backup.auto.last_run_at' => ['value' => null, 'is_sensitive' => false],
        'asset.warning_days_before_expiry' => ['value' => 30, 'is_sensitive' => false],
        'certification.warning_days_before_expiry' => ['value' => 30, 'is_sensitive' => false],
        'location.retention_days' => ['value' => 30, 'is_sensitive' => false],
        'updates.android.minimum_supported_version_code' => ['value' => 1, 'is_sensitive' => false],
        'security.require_2fa_for_coordinators' => ['value' => true, 'is_sensitive' => false],
        'security.password_min_length' => ['value' => 14, 'is_sensitive' => false],
        'security.password_requires_mixed_case' => ['value' => true, 'is_sensitive' => false],
        'security.password_requires_numbers' => ['value' => true, 'is_sensitive' => false],
        'security.password_requires_symbols' => ['value' => true, 'is_sensitive' => false],
        'security.password_uncompromised' => ['value' => true, 'is_sensitive' => false],
        'security.mfa_issuer_name' => ['value' => 'D.I.S', 'is_sensitive' => false],
        'app.setup_completed' => ['value' => false, 'is_sensitive' => false],
        'app.setup_completed_at' => ['value' => null, 'is_sensitive' => false],
        'app.public_url' => ['value' => '', 'is_sensitive' => false],
        'app.brand_name' => ['value' => 'D.I.S Operationeel Beeld', 'is_sensitive' => false],
        'app.brand_short_name' => ['value' => 'DIS', 'is_sensitive' => false],
        'app.login_title' => ['value' => 'D.I.S Command Center', 'is_sensitive' => false],
        'app.login_subtitle' => ['value' => '', 'is_sensitive' => false],
        'app.logo_data_url' => ['value' => '', 'is_sensitive' => false],
        'mail.mailer' => ['value' => 'smtp', 'is_sensitive' => false],
        'mail.host' => ['value' => 'smtp.example.nl', 'is_sensitive' => false],
        'mail.port' => ['value' => 587, 'is_sensitive' => false],
        'mail.encryption' => ['value' => 'tls', 'is_sensitive' => false],
        'mail.username' => ['value' => '', 'is_sensitive' => false],
        'mail.password' => ['value' => '', 'is_sensitive' => true],
        'mail.microsoft365_tenant_id' => ['value' => '', 'is_sensitive' => false],
        'mail.microsoft365_client_id' => ['value' => '', 'is_sensitive' => false],
        'mail.microsoft365_client_secret' => ['value' => '', 'is_sensitive' => true],
        'mail.microsoft365_sender' => ['value' => '', 'is_sensitive' => false],
        'mail.from_address' => ['value' => 'no-reply@dis.example.nl', 'is_sensitive' => false],
        'mail.from_name' => ['value' => 'Drone Inzet Systeem', 'is_sensitive' => false],
        'mail.template.welcome_subject' => ['value' => 'Welkom bij {{app_name}}', 'is_sensitive' => false],
        'mail.template.welcome_body' => ['value' => "Beste {{name}},\n\nEr is een account voor je aangemaakt in {{app_name}}. Rond je registratie af via onderstaande link:\n\n{{registration_url}}\n\nJe stelt zelf je wachtwoord in en doorloopt direct de MFA-setup wanneer dat voor je rol verplicht is.\n\n{{admin_app_note}}\n\nDeze link is tijdelijk geldig. Vraag een beheerder om een nieuwe uitnodiging als de link verlopen is.", 'is_sensitive' => false],
        'mail.template.certification_expiry_subject' => ['value' => '{{certification_name}} - {{status_text}}', 'is_sensitive' => false],
        'mail.template.certification_expiry_body' => ['value' => "Beste {{name}},\n\nJe certificaat {{certification_name}} {{expiry_status}}.\n\nCertificaatnummer: {{certificate_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk je certificaat bij in de app zodra de verlenging rond is. Zonder geldig vereist certificaat kun je niet meegenomen worden in alarmeringen waarvoor dit certificaat verplicht is.", 'is_sensitive' => false],
        'mail.template.asset_expiry_subject' => ['value' => '{{asset_name}} - {{status_text}}', 'is_sensitive' => false],
        'mail.template.asset_expiry_body' => ['value' => "Beste {{name}},\n\nDe verloopdatum of onderhoudsdatum van asset {{asset_name}} {{expiry_status}}.\n\nAsset tag: {{asset_tag}}\nSerienummer: {{serial_number}}\nVerloopdatum: {{expires_at}}\nStatus: {{status_text}}\n\nWerk de assetgegevens bij zodra dit is afgehandeld.", 'is_sensitive' => false],
        'firebase.project_id' => ['value' => '', 'is_sensitive' => false],
        'firebase.service_account' => ['value' => [
            'client_email' => '',
            'private_key' => '',
            'private_key_id' => '',
            'client_id' => '',
            'client_x509_cert_url' => '',
        ], 'is_sensitive' => true],
        'retention.push_logs_days' => ['value' => 90, 'is_sensitive' => false],
        'retention.audit_logs_days' => ['value' => 3650, 'is_sensitive' => false],
        'retention.location_days' => ['value' => 30, 'is_sensitive' => false],
        'updates.android.application_id' => ['value' => 'nl.wrdmarco.dis', 'is_sensitive' => false],
        'mobile.tenant_name' => ['value' => 'Nationaal Droneteam', 'is_sensitive' => false],
        'mobile.api_base_url' => ['value' => '', 'is_sensitive' => false],
        'mobile.firebase_config' => ['value' => [
            'application_id' => '',
            'api_key' => '',
            'project_id' => '',
            'messaging_sender_id' => '',
            'storage_bucket' => '',
        ], 'is_sensitive' => false],
        'pilot_report.form_fields' => ['value' => [
            ['key' => 'summary', 'label' => 'Samenvatting', 'type' => 'textarea', 'visible' => true, 'required' => true, 'max_length' => 5000],
            ['key' => 'observations', 'label' => 'Waarnemingen', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000],
            ['key' => 'actions_taken', 'label' => 'Uitgevoerde acties', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000],
            ['key' => 'result', 'label' => 'Resultaat', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000],
            ['key' => 'equipment_used', 'label' => 'Gebruikte middelen', 'type' => 'text', 'visible' => true, 'required' => false, 'max_length' => 5000],
            ['key' => 'flight_minutes', 'label' => 'Vluchtduur in minuten', 'type' => 'number', 'visible' => true, 'required' => false, 'max' => 1440],
            ['key' => 'issues', 'label' => 'Bijzonderheden of problemen', 'type' => 'textarea', 'visible' => true, 'required' => false, 'max_length' => 5000],
        ], 'is_sensitive' => false],
    ];

    public function run(): void
    {
        $now = Carbon::now();

        foreach ($this->settings as $key => $setting) {
            $exists = DB::table('system_settings')->where('key', $key)->exists();

            if (! $exists) {
                DB::table('system_settings')->insert([
                    'key' => $key,
                    'value' => json_encode($setting['value'], JSON_THROW_ON_ERROR),
                    'is_sensitive' => $setting['is_sensitive'],
                    'updated_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
