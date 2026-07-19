<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallboard_content_snapshots', function (Blueprint $table): void {
            $table->foreignUlid('playlist_id')
                ->constrained('wallboard_playlists')
                ->cascadeOnDelete();
            $table->enum('kind', ['news', 'ticker']);
            $table->char('config_fingerprint', 64);
            $table->unsignedBigInteger('revision')->default(1);
            $table->jsonb('payload');
            $table->timestampTz('checked_at');
            $table->timestampTz('updated_at');

            $table->primary(['playlist_id', 'kind'], 'wallboard_content_snapshots_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallboard_content_snapshots');
    }
};
