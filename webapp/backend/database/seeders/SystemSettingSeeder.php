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
        'push.availability_requires_push' => ['value' => true, 'is_sensitive' => false],
        'location.retention_days' => ['value' => 30, 'is_sensitive' => false],
        'updates.android.minimum_supported_version_code' => ['value' => 1, 'is_sensitive' => false],
        'security.require_2fa_for_coordinators' => ['value' => true, 'is_sensitive' => false],
        'app.setup_completed' => ['value' => false, 'is_sensitive' => false],
        'app.setup_completed_at' => ['value' => null, 'is_sensitive' => false],
        'app.public_url' => ['value' => '', 'is_sensitive' => false],
        'mail.mailer' => ['value' => 'smtp', 'is_sensitive' => false],
        'mail.host' => ['value' => 'smtp.example.nl', 'is_sensitive' => false],
        'mail.port' => ['value' => 587, 'is_sensitive' => false],
        'mail.encryption' => ['value' => 'tls', 'is_sensitive' => false],
        'mail.username' => ['value' => '', 'is_sensitive' => false],
        'mail.password' => ['value' => '', 'is_sensitive' => true],
        'mail.from_address' => ['value' => 'no-reply@dis.example.nl', 'is_sensitive' => false],
        'mail.from_name' => ['value' => 'Drone Inzet Systeem', 'is_sensitive' => false],
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
