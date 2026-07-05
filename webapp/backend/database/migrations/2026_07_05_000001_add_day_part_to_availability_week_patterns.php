<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_week_patterns', function (Blueprint $table): void {
            $table->dropUnique('availability_week_patterns_user_id_day_of_week_unique');
            $table->string('day_part', 24)->default('all_day')->after('day_of_week');
            $table->unique(['user_id', 'day_of_week', 'day_part'], 'availability_week_patterns_user_day_part_unique');
        });
    }

    public function down(): void
    {
        Schema::table('availability_week_patterns', function (Blueprint $table): void {
            $table->dropUnique('availability_week_patterns_user_day_part_unique');
            $table->dropColumn('day_part');
            $table->unique(['user_id', 'day_of_week']);
        });
    }
};
