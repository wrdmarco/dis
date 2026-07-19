<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallboards', function (Blueprint $table): void {
            $table->unsignedBigInteger('refresh_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('wallboards', function (Blueprint $table): void {
            $table->dropColumn('refresh_version');
        });
    }
};
