<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('created_by_name')->nullable()->after('created_by');
            $table->string('created_by_email')->nullable()->after('created_by_name');
            $table->string('coordinator_name')->nullable()->after('coordinator_id');
            $table->string('coordinator_email')->nullable()->after('coordinator_name');
        });

        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->string('requested_by_name')->nullable()->after('requested_by');
            $table->string('requested_by_email')->nullable()->after('requested_by_name');
        });

        Schema::table('dispatch_recipients', function (Blueprint $table): void {
            $table->string('user_name')->nullable()->after('user_id');
            $table->string('user_email')->nullable()->after('user_name');
        });

        Schema::table('availability_statuses', function (Blueprint $table): void {
            $table->string('user_name')->nullable()->after('user_id');
            $table->string('user_email')->nullable()->after('user_name');
            $table->string('changed_by_name')->nullable()->after('changed_by');
            $table->string('changed_by_email')->nullable()->after('changed_by_name');
        });

        Schema::table('dispatch_messages', function (Blueprint $table): void {
            $table->string('sent_by_name')->nullable()->after('sent_by');
            $table->string('sent_by_email')->nullable()->after('sent_by_name');
        });

        Schema::table('incident_status_history', function (Blueprint $table): void {
            $table->string('changed_by_name')->nullable()->after('changed_by');
            $table->string('changed_by_email')->nullable()->after('changed_by_name');
        });

        $this->snapshot('incidents', 'created_by', 'created_by_name', 'created_by_email');
        $this->snapshot('incidents', 'coordinator_id', 'coordinator_name', 'coordinator_email');
        $this->snapshot('dispatch_requests', 'requested_by', 'requested_by_name', 'requested_by_email');
        $this->snapshot('dispatch_recipients', 'user_id', 'user_name', 'user_email');
        $this->snapshot('availability_statuses', 'user_id', 'user_name', 'user_email');
        $this->snapshot('availability_statuses', 'changed_by', 'changed_by_name', 'changed_by_email');
        $this->snapshot('dispatch_messages', 'sent_by', 'sent_by_name', 'sent_by_email');
        $this->snapshot('incident_status_history', 'changed_by', 'changed_by_name', 'changed_by_email');

        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
        });

        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->dropForeign(['requested_by']);
        });

        Schema::table('dispatch_recipients', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('availability_statuses', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('incident_status_history', function (Blueprint $table): void {
            $table->dropColumn(['changed_by_name', 'changed_by_email']);
        });

        Schema::table('dispatch_messages', function (Blueprint $table): void {
            $table->dropColumn(['sent_by_name', 'sent_by_email']);
        });

        Schema::table('availability_statuses', function (Blueprint $table): void {
            $table->dropColumn(['user_name', 'user_email', 'changed_by_name', 'changed_by_email']);
        });

        Schema::table('dispatch_recipients', function (Blueprint $table): void {
            $table->dropColumn(['user_name', 'user_email']);
        });

        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->dropColumn(['requested_by_name', 'requested_by_email']);
        });

        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn(['created_by_name', 'created_by_email', 'coordinator_name', 'coordinator_email']);
        });
    }

    private function snapshot(string $table, string $userIdColumn, string $nameColumn, string $emailColumn): void
    {
        DB::statement(sprintf(
            'UPDATE %1$s SET %3$s = (SELECT name FROM users WHERE users.id = %1$s.%2$s), %4$s = (SELECT email FROM users WHERE users.id = %1$s.%2$s) WHERE %2$s IS NOT NULL AND (%3$s IS NULL OR %4$s IS NULL)',
            $table,
            $userIdColumn,
            $nameColumn,
            $emailColumn,
        ));
    }
};
