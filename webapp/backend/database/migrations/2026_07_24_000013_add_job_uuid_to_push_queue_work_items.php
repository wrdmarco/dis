<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_queue_work_items', function (Blueprint $table): void {
            // failed_jobs.uuid is intentionally a string in Laravel's
            // database-uuids schema; use the same type for indexed joins.
            $table->string('queue_job_uuid', 36)
                ->nullable()
                ->unique('push_queue_work_job_uuid_unique')
                ->after('queue_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('push_queue_work_items', function (Blueprint $table): void {
            $table->dropUnique('push_queue_work_job_uuid_unique');
            $table->dropColumn('queue_job_uuid');
        });
    }
};
