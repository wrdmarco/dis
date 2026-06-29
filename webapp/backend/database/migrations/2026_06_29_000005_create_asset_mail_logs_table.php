<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_mail_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notification_type', 40);
            $table->date('expires_at');
            $table->date('sent_for_date');
            $table->timestampTz('sent_at');
            $table->timestampsTz();
            $table->unique(['asset_id', 'user_id', 'notification_type', 'expires_at', 'sent_for_date'], 'asset_mail_once_per_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_mail_logs');
    }
};
