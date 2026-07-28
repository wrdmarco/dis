<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_DESCRIPTION = 'Maak agenda-items aan en verwijder bestaande agenda-items. Vereist daarnaast Agenda bekijken.';

    private const NEW_DESCRIPTION = 'Maak agenda-items aan, pas bestaande agenda-items aan en verwijder ze. Vereist daarnaast Agenda bekijken.';

    public function up(): void
    {
        DB::table('permissions')
            ->where('name', 'calendar.manage')
            ->where('description', self::OLD_DESCRIPTION)
            ->update([
                'description' => self::NEW_DESCRIPTION,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'calendar.manage')
            ->where('description', self::NEW_DESCRIPTION)
            ->update([
                'description' => self::OLD_DESCRIPTION,
                'updated_at' => now(),
            ]);
    }
};
