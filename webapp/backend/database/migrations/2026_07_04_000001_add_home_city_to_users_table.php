<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('home_city', 120)->nullable()->after('phone_number');
            $table->decimal('home_latitude', 8, 2)->nullable()->after('home_city');
            $table->decimal('home_longitude', 8, 2)->nullable()->after('home_latitude');
            $table->timestampTz('home_geocoded_at')->nullable()->after('home_longitude');
            $table->string('home_geocode_source', 40)->nullable()->after('home_geocoded_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'home_city',
                'home_latitude',
                'home_longitude',
                'home_geocoded_at',
                'home_geocode_source',
            ]);
        });
    }
};
