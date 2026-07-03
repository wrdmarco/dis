<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->boolean('includes_unavailable_recipients')->default(false)->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->dropColumn('includes_unavailable_recipients');
        });
    }
};
