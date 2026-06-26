<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('drone_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('manufacturer')->index();
            $table->string('model')->unique();
            $table->boolean('has_thermal')->default(false)->index();
            $table->boolean('has_spotlight')->default(false)->index();
            $table->boolean('has_speaker')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->foreignUlid('drone_type_id')->nullable()->after('type')->constrained('drone_types')->nullOnDelete();
        });

        DB::table('drone_types')->insert([
            [
                'id' => (string) Str::ulid(),
                'manufacturer' => 'DJI',
                'model' => 'DJI Mini 5 Pro',
                'has_thermal' => false,
                'has_spotlight' => false,
                'has_speaker' => false,
                'is_active' => true,
                'notes' => 'DJI specs list a 1-inch CMOS camera; no integrated thermal camera.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'manufacturer' => 'DJI',
                'model' => 'DJI Air 3',
                'has_thermal' => false,
                'has_spotlight' => false,
                'has_speaker' => false,
                'is_active' => true,
                'notes' => 'DJI product information describes dual visible-light cameras; no integrated thermal camera.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'manufacturer' => 'DJI',
                'model' => 'DJI Air 3S',
                'has_thermal' => false,
                'has_spotlight' => false,
                'has_speaker' => false,
                'is_active' => true,
                'notes' => 'DJI product information describes a 1-inch CMOS primary camera and 70mm medium tele camera; no integrated thermal camera.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'manufacturer' => 'DJI',
                'model' => 'DJI Matrice 4T',
                'has_thermal' => true,
                'has_spotlight' => true,
                'has_speaker' => true,
                'is_active' => true,
                'notes' => 'DJI Enterprise Matrice 4T typekeuze met thermal, externe lamp en speaker ondersteuning.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'manufacturer' => 'DJI',
                'model' => 'DJI Matrice 4TD',
                'has_thermal' => true,
                'has_spotlight' => true,
                'has_speaker' => true,
                'is_active' => true,
                'notes' => 'DJI Enterprise Matrice 4TD typekeuze met thermal, externe lamp en speaker ondersteuning.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('drone_type_id');
        });
        Schema::dropIfExists('drone_types');
    }
};
