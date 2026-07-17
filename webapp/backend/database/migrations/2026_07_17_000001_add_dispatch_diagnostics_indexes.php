<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_delivery_logs', function (Blueprint $table): void {
            $table->index(
                ['dispatch_request_id', 'created_at'],
                'push_delivery_dispatch_created_idx',
            );
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(
                ['target_type', 'target_id', 'created_at'],
                'audit_target_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_target_created_idx');
        });
        Schema::table('push_delivery_logs', function (Blueprint $table): void {
            $table->dropIndex('push_delivery_dispatch_created_idx');
        });
    }
};
