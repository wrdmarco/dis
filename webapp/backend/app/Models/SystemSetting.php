<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SystemSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'is_sensitive', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array', 'is_sensitive' => 'boolean'];
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
