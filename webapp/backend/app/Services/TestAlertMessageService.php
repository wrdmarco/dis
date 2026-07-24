<?php

namespace App\Services;

use App\Models\SystemSetting;

final class TestAlertMessageService
{
    public const DEFAULT_MESSAGE = 'Dit is het wekelijkse proefalarm.';

    public function configuredMessage(): string
    {
        return SystemSetting::string('test_alert.message', self::DEFAULT_MESSAGE) ?? self::DEFAULT_MESSAGE;
    }

    public function deliveredMessage(): string
    {
        $cleaned = preg_replace([
            '/\s*Bevestig deze proefalarmering met Ontvangen(?: in de app)?\.?/iu',
            '/\s*Bevestig ontvangst met de knop Ontvangen\.?/iu',
        ], '', $this->configuredMessage());
        $cleaned = trim((string) $cleaned);

        return $cleaned !== '' ? $cleaned : self::DEFAULT_MESSAGE;
    }
}
