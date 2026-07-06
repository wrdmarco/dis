<?php

namespace App\Console\Commands;

use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use Illuminate\Console\Command;

final class SendDevicePresencePing extends Command
{
    protected $signature = 'dis:send-device-presence-ping';

    protected $description = 'Send silent presence pings to active operator devices.';

    public function handle(): int
    {
        $tokens = FcmToken::query()
            ->where('client_type', 'operator')
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query
                ->where('account_status', 'active')
                ->where('push_enabled', true))
            ->get(['id']);

        foreach ($tokens as $token) {
            SendFcmNotification::dispatch(
                (string) $token->id,
                'device_presence_ping',
                'D.I.S',
                'Presence check',
                ['type' => 'device_presence_ping'],
            )->onQueue('push');
        }

        $this->info('Device presence pings queued: '.$tokens->count().'.');

        return self::SUCCESS;
    }
}
