<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('certification_mail_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_certification_id')->constrained('user_certifications')->cascadeOnDelete();
            $table->string('notification_type', 40);
            $table->date('expires_at');
            $table->date('sent_for_date');
            $table->timestampTz('sent_at');
            $table->timestampsTz();
            $table->unique(['user_certification_id', 'notification_type', 'expires_at', 'sent_for_date'], 'cert_mail_once_per_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_mail_logs');
    }
};
