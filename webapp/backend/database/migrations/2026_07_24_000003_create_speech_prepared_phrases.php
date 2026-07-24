<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'speech.cache.view' => [
            'display_name' => 'Blijvende spraakvoorbereidingen bekijken',
            'description' => 'Bekijk blijvend voorbereide woonplaatsen, provincies, postcodes en vaste spraakzinnen.',
        ],
        'speech.cache.manage' => [
            'display_name' => 'Blijvende spraakvoorbereidingen beheren',
            'description' => 'Voeg blijvende spraakvoorbereidingen toe, verwijder ze en leeg de voorbereidingsbibliotheek.',
        ],
    ];

    public function up(): void
    {
        Schema::table('speech_audio_assets', function (Blueprint $table): void {
            $table->timestampTz('orphaned_at')->nullable()->index();
        });

        Schema::table('speech_cache_entries', function (Blueprint $table): void {
            $table->boolean('is_pinned')->default(false)->index();
            $table->timestampTz('pinned_at')->nullable();
        });

        Schema::create('speech_prepared_phrases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 24)->index();
            $table->char('identity_hmac', 64);
            $table->text('display_text');
            $table->string('status', 24)->index();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->foreignUlid('cache_entry_id')->nullable()
                ->constrained('speech_cache_entries')->nullOnDelete();
            $table->char('runtime_fingerprint_hmac', 64)->nullable()->index();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('prepared_at')->nullable();
            $table->timestampsTz();
            $table->unique(['kind', 'identity_hmac'], 'speech_prepared_phrase_identity_unique');
            $table->index(['kind', 'status', 'created_at'], 'speech_prepared_phrase_management_idx');
        });

        $now = Carbon::now();
        $administratorRoleId = DB::table('roles')
            ->where('name', 'system-administrator')
            ->value('id');

        foreach (self::PERMISSIONS as $name => $definition) {
            $permissionId = (string) (DB::table('permissions')
                ->where('name', $name)
                ->value('id') ?? Str::ulid());
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => $permissionId,
                    'display_name' => $definition['display_name'],
                    'category' => 'system_configuration',
                    'description' => $definition['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            if (is_string($administratorRoleId)) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'permission_id' => $permissionId,
                        'role_id' => $administratorRoleId,
                    ],
                    ['created_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('speech_prepared_phrases');

        Schema::table('speech_cache_entries', function (Blueprint $table): void {
            $table->dropIndex(['is_pinned']);
            $table->dropColumn(['is_pinned', 'pinned_at']);
        });

        Schema::table('speech_audio_assets', function (Blueprint $table): void {
            $table->dropIndex(['orphaned_at']);
            $table->dropColumn('orphaned_at');
        });

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys(self::PERMISSIONS))
            ->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
