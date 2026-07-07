<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        $legacyValue = DB::table('system_settings')->where('key', 'security.require_2fa_for_coordinators')->value('value');
        $legacyRequired = DB::table('roles')->where('requires_two_factor', true)->exists()
            || $legacyValue === true
            || $legacyValue === 1
            || $legacyValue === '1'
            || $legacyValue === 'true'
            || $legacyValue === '"true"';

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'security.mfa_required'],
            [
                'value' => json_encode($legacyRequired, JSON_THROW_ON_ERROR),
                'is_sensitive' => false,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('roles')->where('requires_two_factor', true)->update([
            'requires_two_factor' => false,
            'updated_at' => $now,
        ]);

        $oldWelcomeBody = "Beste {{name}},\n\nEr is een account voor je aangemaakt in {{app_name}}. Rond je registratie af via onderstaande link:\n\n{{registration_url}}\n\nJe stelt zelf je wachtwoord in en doorloopt direct de MFA-setup wanneer dat voor je rol verplicht is.\n\n{{admin_app_note}}\n\nDeze link is tijdelijk geldig. Vraag een beheerder om een nieuwe uitnodiging als de link verlopen is.";
        $newWelcomeBody = "Beste {{name}},\n\nEr is een account voor je aangemaakt in {{app_name}}. Rond je registratie af via onderstaande link:\n\n{{registration_url}}\n\nJe stelt zelf je wachtwoord in en doorloopt direct de MFA-setup wanneer dit systeemwijd verplicht is.\n\n{{admin_app_note}}\n\nDeze link is tijdelijk geldig. Vraag een beheerder om een nieuwe uitnodiging als de link verlopen is.";

        DB::table('system_settings')
            ->where('key', 'mail.template.welcome_body')
            ->where('value', json_encode($oldWelcomeBody, JSON_THROW_ON_ERROR))
            ->update([
                'value' => json_encode($newWelcomeBody, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'security.mfa_required')->delete();
    }
};
