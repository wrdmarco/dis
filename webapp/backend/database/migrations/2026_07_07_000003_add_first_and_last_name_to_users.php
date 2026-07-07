<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name', 80)->nullable()->after('name');
            $table->string('last_name', 120)->nullable()->after('first_name');
            $table->string('home_region', 120)->nullable()->after('home_city');
            $table->string('home_country', 2)->nullable()->after('home_region');
        });

        foreach (DB::table('users')->select(['id', 'name'])->get() as $user) {
            [$firstName, $lastName] = $this->splitDisplayName((string) ($user->name ?? ''));
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'home_country' => 'NL',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'home_region', 'home_country']);
        });
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitDisplayName(string $name): array
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($normalized === '') {
            return [null, null];
        }

        $parts = explode(' ', $normalized, 2);

        return [
            $parts[0] !== '' ? $parts[0] : null,
            isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null,
        ];
    }
};
