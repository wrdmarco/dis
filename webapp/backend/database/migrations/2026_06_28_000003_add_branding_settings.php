<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * @var array<string, string>
     */
    private array $settings = [
        'app.brand_name' => 'D.I.S Operationeel Beeld',
        'app.brand_short_name' => 'DIS',
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->settings as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'is_sensitive' => false,
                    'updated_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_keys($this->settings))->delete();
    }
};
