<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('osrm_operations', function (Blueprint $table): void {
            $table->text('source_url')->nullable()->change();
            $table->char('source_sha256', 64)->nullable()->change();
            $table->json('source_set')->nullable()->after('source_sha256');
            $table->json('source_manifest')->nullable()->after('source_set');
            $table->char('source_set_sha256', 64)->nullable()->after('source_manifest');
        });
    }

    public function down(): void
    {
        $hasCompositeOperations = DB::table('osrm_operations')
            ->whereNotNull('source_set')
            ->orWhereNotNull('source_manifest')
            ->orWhereNotNull('source_set_sha256')
            ->orWhereNull('source_url')
            ->orWhereNull('source_sha256')
            ->exists();
        if ($hasCompositeOperations) {
            throw new RuntimeException(
                'Cannot safely roll back the composite OSRM source migration while composite operations exist.',
            );
        }

        Schema::table('osrm_operations', function (Blueprint $table): void {
            $table->dropColumn(['source_set', 'source_manifest', 'source_set_sha256']);
            $table->text('source_url')->nullable(false)->change();
            $table->char('source_sha256', 64)->nullable(false)->change();
        });
    }
};
