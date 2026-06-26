<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->boolean('has_spotlight')->default(false)->after('drone_type_id')->index();
            $table->boolean('has_speaker')->default(false)->after('has_spotlight')->index();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn(['has_spotlight', 'has_speaker']);
        });
    }
};
