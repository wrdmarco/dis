<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->timestampTz('preannounced_at')->nullable()->index();
        });

        // A draft only represents an active preannouncement when at least one
        // recipient was notified. Use portable per-dispatch aggregation so the
        // migration behaves identically on PostgreSQL and SQLite.
        $dispatchIds = DB::table('dispatch_requests')
            ->where('status', 'draft')
            ->whereNull('preannounced_at')
            ->pluck('id');

        foreach ($dispatchIds as $dispatchId) {
            $preannouncedAt = DB::table('dispatch_recipients')
                ->where('dispatch_request_id', $dispatchId)
                ->whereNotNull('notified_at')
                ->min('notified_at');
            if ($preannouncedAt !== null) {
                DB::table('dispatch_requests')
                    ->where('id', $dispatchId)
                    ->update(['preannounced_at' => $preannouncedAt]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->dropIndex(['preannounced_at']);
            $table->dropColumn('preannounced_at');
        });
    }
};
