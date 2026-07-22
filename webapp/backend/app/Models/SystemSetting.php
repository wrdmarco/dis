<?php

namespace App\Models;

use App\Casts\SystemSettingValueCast;
use Illuminate\Database\Eloquent\Model;

final class SystemSetting extends Model
{
    private const SENSITIVE_KEYS = [
        'backup.samba.password',
        'developer.android_upload',
        'drone.aeret_api_key',
        'weather.knmi_edr_api_key',
        'weather.knmi_open_data_api_key',
        'firebase.service_account',
        'push.apns.credentials',
        'mail.microsoft365_client_secret',
        'mail.password',
        'speech.templates.availability',
        'speech.templates.attendance',
        'speech.templates.test_ack',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'is_sensitive', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => SystemSettingValueCast::class, 'is_sensitive' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::saving(function (SystemSetting $setting): void {
            if (self::isSensitiveKey((string) $setting->getKey())) {
                $setting->is_sensitive = true;
            }
        });
    }

    public static function isSensitiveKey(string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true);
    }

    public static function value(string $key, mixed $default = null): mixed
    {
        $setting = self::query()->find($key);

        return $setting === null ? $default : $setting->value;
    }

    public static function string(string $key, ?string $default = null): ?string
    {
        $value = self::value($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function integer(string $key, int $default): int
    {
        $value = self::value($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function boolean(string $key, bool $default): bool
    {
        $value = self::value($key, $default);

        return is_bool($value) ? $value : $default;
    }
}
