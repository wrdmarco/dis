<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'incident.timeline.app_visible_types'],
            [
                'value' => json_encode(['status', 'dispatch', 'dispatch_response', 'dispatch_message', 'operator_status'], JSON_THROW_ON_ERROR),
                'is_sensitive' => false,
                'updated_by' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'incident.timeline.app_visible_types')->delete();
    }
};
