<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallboard_playlists', function (Blueprint $table): void {
            $table->enum('data_mode', ['live', 'demo'])
                ->default('live')
                ->after('name')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('wallboard_playlists', function (Blueprint $table): void {
            $table->dropIndex(['data_mode']);
            $table->dropColumn('data_mode');
        });
    }
};
