<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * @var array<string, array{value: mixed, is_sensitive: bool}>
     */
    private array $settings = [
        'mail.microsoft365_tenant_id' => ['value' => '', 'is_sensitive' => false],
        'mail.microsoft365_client_id' => ['value' => '', 'is_sensitive' => false],
        'mail.microsoft365_client_secret' => ['value' => '', 'is_sensitive' => true],
        'mail.microsoft365_sender' => ['value' => '', 'is_sensitive' => false],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->settings as $key => $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => json_encode($setting['value'], JSON_THROW_ON_ERROR),
                    'is_sensitive' => $setting['is_sensitive'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_keys($this->settings))->delete();
    }
};
