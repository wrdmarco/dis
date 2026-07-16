<?php

namespace App\Support;

final class PushNotificationIdentity
{
    /**
     * A collapse identifier only coalesces duplicate deliveries that are still
     * pending at the provider. It is not an exactly-once guarantee: durable
     * alarm delivery remains intentionally at-least-once.
     *
     * @param  array<string, string>  $data
     */
    public static function dispatchCollapseId(array $data): ?string
    {
        $dispatchId = $data['dispatch_id'] ?? null;
        if (($data['type'] ?? null) !== 'dispatch_request'
            || ! is_string($dispatchId)
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $dispatchId) !== 1) {
            return null;
        }

        return 'dispatch-'.$dispatchId;
    }
}
