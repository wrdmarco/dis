<?php

namespace App\Casts;

use App\Models\SystemSetting;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * @implements CastsAttributes<mixed, mixed>
 */
final class SystemSettingValueCast implements CastsAttributes
{
    public const ENVELOPE_KEY = '__encrypted_v1';

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $decoded = $this->decodeJsonValue($value);
        if (! is_array($decoded) || ! is_string($decoded[self::ENVELOPE_KEY] ?? null)) {
            return $decoded;
        }

        try {
            return json_decode(Crypt::decryptString($decoded[self::ENVELOPE_KEY]), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException('A sensitive system setting could not be decrypted.', previous: $exception);
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $settingKey = (string) ($attributes['key'] ?? $model->getKey() ?? '');
        $isSensitive = filter_var($attributes['is_sensitive'] ?? false, FILTER_VALIDATE_BOOL)
            || SystemSetting::isSensitiveKey($settingKey);

        if ($isSensitive) {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $value = [self::ENVELOPE_KEY => Crypt::encryptString($json)];
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
