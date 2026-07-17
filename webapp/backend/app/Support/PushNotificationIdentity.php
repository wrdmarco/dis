<?php

namespace App\Support;

final class PushNotificationIdentity
{
    /**
     * Keep every phase of one dispatch on the same provider ordering key.
     *
     * FCM may defer a normal-priority message while allowing a later
     * high-priority alarm to overtake it. Availability response syncs are no
     * longer emitted because older clients could then silence the real alarm.
     * This shared collapse identifier remains defence in depth for all other
     * lifecycle messages and messages queued during a rolling deployment.
     *
     * This only coalesces provider-pending messages. It is not an exactly-once
     * guarantee: durable alarm delivery remains intentionally at-least-once.
     *
     * @param  array<string, string>  $data
     */
    public static function dispatchCollapseId(array $data): ?string
    {
        $dispatchId = $data['dispatch_id'] ?? null;
        if (! is_string($dispatchId)
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $dispatchId) !== 1) {
            return null;
        }

        $type = $data['type'] ?? null;
        $actionMode = $data['action_mode'] ?? null;
        $isOrderedDispatchPhase = $type === 'dispatch_request'
            || $type === 'incident_preannouncement'
            || ($type === 'dispatch_update' && $actionMode === 'availability')
            || ($type === 'dispatch_response_sync'
                && in_array($actionMode, ['availability', 'attendance', 'test_ack'], true));
        if (! $isOrderedDispatchPhase) {
            return null;
        }

        return 'dispatch-'.$dispatchId;
    }
}
