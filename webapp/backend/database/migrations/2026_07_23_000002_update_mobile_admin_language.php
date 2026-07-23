<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_WELCOME_BODY = "Beste {{name}},\n\nEr is een account voor je aangemaakt in {{app_name}}. Rond je registratie af via onderstaande link:\n\n{{registration_url}}\n\nJe stelt zelf je wachtwoord in en doorloopt direct de MFA-setup wanneer dit systeemwijd verplicht is.\n\n{{admin_app_note}}\n\nDeze link is tijdelijk geldig. Vraag een beheerder om een nieuwe uitnodiging als de link verlopen is.";

    private const NEW_WELCOME_BODY = "Beste {{name}},\n\nEr is een account voor je aangemaakt in {{app_name}}. Rond je registratie af via onderstaande link:\n\n{{registration_url}}\n\nJe stelt zelf je wachtwoord in en doorloopt direct de MFA-setup wanneer dit systeemwijd verplicht is.\n\n{{web_admin_note}}\n\nDeze link is tijdelijk geldig. Vraag een beheerder om een nieuwe uitnodiging als de link verlopen is.";

    private const OLD_ROLE_DESCRIPTION = 'Maak rollen aan, wijzig rolrechten en bepaal toegang tot operator- en admin-app.';

    private const NEW_ROLE_DESCRIPTION = 'Maak rollen aan, wijzig rolrechten en bepaal toegang tot de Operator-app en webbeheer.';

    public function up(): void
    {
        DB::table('system_settings')
            ->where('key', 'mail.template.welcome_body')
            ->where('value', json_encode(self::OLD_WELCOME_BODY, JSON_THROW_ON_ERROR))
            ->update(['value' => json_encode(self::NEW_WELCOME_BODY, JSON_THROW_ON_ERROR)]);

        DB::table('permissions')
            ->where('name', 'roles.manage')
            ->where('description', self::OLD_ROLE_DESCRIPTION)
            ->update(['description' => self::NEW_ROLE_DESCRIPTION]);
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'mail.template.welcome_body')
            ->where('value', json_encode(self::NEW_WELCOME_BODY, JSON_THROW_ON_ERROR))
            ->update(['value' => json_encode(self::OLD_WELCOME_BODY, JSON_THROW_ON_ERROR)]);

        DB::table('permissions')
            ->where('name', 'roles.manage')
            ->where('description', self::NEW_ROLE_DESCRIPTION)
            ->update(['description' => self::OLD_ROLE_DESCRIPTION]);
    }
};
