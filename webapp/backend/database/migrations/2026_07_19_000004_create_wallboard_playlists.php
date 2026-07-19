<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallboard_playlists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->json('configuration');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('name');
        });

        Schema::table('wallboards', function (Blueprint $table): void {
            $table->foreignUlid('playlist_id')
                ->nullable()
                ->after('id')
                ->constrained('wallboard_playlists')
                ->restrictOnDelete();
        });

        DB::table('wallboards')
            ->select(['id', 'name', 'configuration', 'created_by', 'updated_by', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(100, function ($wallboards): void {
                foreach ($wallboards as $wallboard) {
                    $playlistId = (string) Str::ulid();
                    $configuration = $wallboard->configuration;
                    if (! is_string($configuration)) {
                        $configuration = json_encode($configuration, JSON_THROW_ON_ERROR);
                    }

                    DB::table('wallboard_playlists')->insert([
                        'id' => $playlistId,
                        'name' => (string) $wallboard->name,
                        // Preserve the stored JSON exactly. Runtime writes normalize
                        // new values, but a data migration must not reinterpret or
                        // discard configuration written by an older release.
                        'configuration' => $configuration,
                        'version' => 1,
                        'created_by' => $wallboard->created_by,
                        'updated_by' => $wallboard->updated_by,
                        'created_at' => $wallboard->created_at,
                        'updated_at' => $wallboard->updated_at,
                    ]);

                    DB::table('wallboards')->where('id', $wallboard->id)->update([
                        'playlist_id' => $playlistId,
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('wallboards', function (Blueprint $table): void {
            $table->dropForeign(['playlist_id']);
            $table->dropColumn('playlist_id');
        });

        Schema::dropIfExists('wallboard_playlists');
    }
};
