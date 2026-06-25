<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * @var array<string, mixed>
     */
    private array $settings = [
        'security.password_min_length' => 14,
        'security.password_requires_mixed_case' => true,
        'security.password_requires_numbers' => true,
        'security.password_requires_symbols' => true,
        'security.password_uncompromised' => true,
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->settings as $key => $value) {
            $exists = DB::table('system_settings')->where('key', $key)->exists();
            if ($exists) {
                continue;
            }

            DB::table('system_settings')->insert([
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'is_sensitive' => false,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_keys($this->settings))->delete();
    }
};
