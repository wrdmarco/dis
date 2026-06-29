<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $defaultCertificationDays = (int) (DB::table('certifications')->max('warning_days_before_expiry') ?? 30);

        foreach ([
            'asset.warning_days_before_expiry' => 30,
            'certification.warning_days_before_expiry' => max(1, $defaultCertificationDays),
        ] as $key => $value) {
            $exists = DB::table('system_settings')->where('key', $key)->exists();

            if ($exists) {
                DB::table('system_settings')->where('key', $key)->update([
                    'value' => json_encode($value),
                    'is_sensitive' => false,
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('system_settings')->insert([
                    'key' => $key,
                    'value' => json_encode($value),
                    'is_sensitive' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'certification.warning_days_before_expiry')->delete();
    }
};
