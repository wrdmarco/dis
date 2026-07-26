<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->boolean('intake_decision_valid')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn('intake_decision_valid');
        });
    }
};
