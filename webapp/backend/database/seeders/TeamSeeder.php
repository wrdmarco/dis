<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TeamSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $ocpId = $this->idFor('OCP');

        DB::table('teams')->updateOrInsert(
            ['code' => 'OCP'],
            [
                'id' => $ocpId,
                'name' => 'Operationeel Coordinatie Platform',
                'type' => 'base',
                'parent_team_id' => null,
                'is_operational' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('teams')->updateOrInsert(
            ['code' => 'TUI'],
            [
                'id' => $this->idFor('TUI'),
                'name' => 'Team Unmanned Inzet',
                'type' => 'subset',
                'parent_team_id' => $ocpId,
                'is_operational' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function idFor(string $code): string
    {
        $existing = DB::table('teams')->where('code', $code)->value('id');

        return $existing !== null ? (string) $existing : (string) Str::ulid();
    }
}

