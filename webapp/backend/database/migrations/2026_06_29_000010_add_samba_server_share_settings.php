<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, array{value: mixed, is_sensitive: bool}>
     */
    private array $settings = [
        'backup.samba.server' => ['value' => '', 'is_sensitive' => false],
        'backup.samba.share_name' => ['value' => '', 'is_sensitive' => false],
    ];

    public function up(): void
    {
        foreach ($this->settings as $key => $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => json_encode($setting['value']),
                    'is_sensitive' => $setting['is_sensitive'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_keys($this->settings))->delete();
    }
};
