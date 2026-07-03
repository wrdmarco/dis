<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('personal_access_tokens')
            ->where('abilities', 'like', '%client:web%')
            ->orWhere(function ($query): void {
                $query
                    ->where('name', 'DIS Command Center')
                    ->where('abilities', 'not like', '%client:operator%')
                    ->where('abilities', 'not like', '%client:admin%');
            })
            ->delete();
    }

    public function down(): void
    {
        // Tokens cannot be restored after logout.
    }
};
