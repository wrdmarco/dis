<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployment_requests', function (Blueprint $table): void {
            $table->string('title', 180)->nullable()->after('subject_type');
        });
    }

    public function down(): void
    {
        Schema::table('deployment_requests', function (Blueprint $table): void {
            $table->dropColumn('title');
        });
    }
};
