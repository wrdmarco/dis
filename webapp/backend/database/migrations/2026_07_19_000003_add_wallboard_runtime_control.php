<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallboards', function (Blueprint $table): void {
            $table->unsignedBigInteger('control_version')->default(1);
            $table->string('manual_page_id', 64)->nullable();
            $table->timestampTz('manual_page_set_at')->nullable();
            $table->timestampTz('rotation_started_at')->nullable();
        });

        DB::table('wallboards')->whereNull('rotation_started_at')->update([
            'rotation_started_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('wallboards', function (Blueprint $table): void {
            $table->dropColumn([
                'control_version',
                'manual_page_id',
                'manual_page_set_at',
                'rotation_started_at',
            ]);
        });
    }
};
