<?php

namespace App\Services;

use App\Models\PushQueueWorkItem;

final class PushQueueManualActionPolicy
{
    private const SAFE_STANDALONE_START_TYPES = [
        'device_presence_ping',
        'dispatch_request',
        'deployment_preannouncement',
        'manual_admin',
        'session_revoked',
    ];

    public function canStart(PushQueueWorkItem $item): bool
    {
        return $item->dispatch_push_outbox_id !== null
            || in_array(
                (string) $item->safe_message_type,
                self::SAFE_STANDALONE_START_TYPES,
                true,
            );
    }
}
