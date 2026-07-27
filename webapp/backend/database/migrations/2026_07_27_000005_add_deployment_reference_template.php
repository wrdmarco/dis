<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SETTING_KEY = 'deployment.reference_template';

    private const DEFAULT_TEMPLATE = 'DIS-{{date}}-{{time}}-{{random}}';

    private const COUNTER_SCOPE = 'global';

    public function up(): void
    {
        Schema::create('deployment_reference_sequence_counters', function (Blueprint $table): void {
            $table->string('scope', 40)->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestampsTz();
        });

        Schema::table('deployments', function (Blueprint $table): void {
            // Existing references remain untouched. Only deployments created
            // after this migration reserve a sequence.
            $table->unsignedBigInteger('reference_sequence')->nullable()->unique();
        });

        $now = now();
        DB::table('deployment_reference_sequence_counters')->insert([
            'scope' => self::COUNTER_SCOPE,
            'last_sequence' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('system_settings')->insertOrIgnore([
            'key' => self::SETTING_KEY,
            'value' => json_encode(self::DEFAULT_TEMPLATE, JSON_THROW_ON_ERROR),
            'is_sensitive' => false,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', self::SETTING_KEY)->delete();

        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropUnique(['reference_sequence']);
            $table->dropColumn('reference_sequence');
        });

        Schema::dropIfExists('deployment_reference_sequence_counters');
    }
};
