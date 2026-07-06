<?php

namespace App\Console\Commands;

use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

final class SendDevicePresencePing extends Command
{
    protected $signature = 'dis:send-device-presence-ping';

    protected $description = 'Send silent presence pings to active operator devices.';

    public function handle(): int
    {
        $heartbeatIntervalMinutes = max(15, SystemSetting::integer('devices.heartbeat_interval_minutes', 15));
        $tokens = FcmToken::query()
            ->where('client_type', 'operator')
            ->where('is_active', true)
            ->where(function ($query) use ($heartbeatIntervalMinutes): void {
                $query
                    ->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<=', now()->subMinutes($heartbeatIntervalMinutes));
            })
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
