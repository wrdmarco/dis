<?php

use App\Casts\SystemSettingValueCast;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SENSITIVE_KEYS = [
        'backup.samba.password',
        'developer.android_upload',
        'drone.aeret_api_key',
        'firebase.service_account',
        'mail.microsoft365_client_secret',
        'mail.password',
    ];

    public function up(): void
    {
        DB::table('system_settings')
            ->where(function ($settings): void {
                $settings->where('is_sensitive', true)->orWhereIn('key', self::SENSITIVE_KEYS);
            })
            ->orderBy('key')
            ->get(['key', 'value'])
            ->each(function (object $setting): void {
                $value = is_string($setting->value)
                    ? json_decode($setting->value, true, 512, JSON_THROW_ON_ERROR)
                    : $setting->value;
                if (is_array($value) && is_string($value[SystemSettingValueCast::ENVELOPE_KEY] ?? null)) {
                    return;
                }

                $encrypted = Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                DB::table('system_settings')->where('key', $setting->key)->update([
                    'value' => json_encode([SystemSettingValueCast::ENVELOPE_KEY => $encrypted], JSON_THROW_ON_ERROR),
                    'is_sensitive' => true,
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Sensitive settings remain encrypted on rollback.
    }
};
