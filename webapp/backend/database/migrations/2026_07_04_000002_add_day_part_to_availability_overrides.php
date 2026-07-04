<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_overrides', function (Blueprint $table): void {
            $table->string('day_part', 20)->default('all_day')->after('ends_at');
            $table->index(['user_id', 'starts_at', 'ends_at', 'day_part']);
        });
    }

    public function down(): void
    {
        Schema::table('availability_overrides', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'starts_at', 'ends_at', 'day_part']);
            $table->dropColumn('day_part');
        });
    }
};
