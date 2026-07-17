<?php

namespace App\Support;

use Illuminate\Support\Str;

final class PushNotificationIdentity
{
    /**
     * All operational phases for one incident and device share one distributed
     * provider-submission lock. Identifiers are hashed so cache keys do not
     * expose operational IDs.
     *
     * @param  array<string, string>  $data
     */
    public static function deliveryOrderLockKey(
        array $data,
        string $fcmTokenId,
        ?string $dispatchRequestId = null,
    ): ?string {
        $incidentId = $data['incident_id'] ?? null;
        $scopeId = is_string($incidentId) && Str::isUlid($incidentId)
            ? $incidentId
            : $dispatchRequestId;
        if (! is_string($scopeId) || ! Str::isUlid($scopeId) || ! Str::isUlid($fcmTokenId)) {
            return null;
        }

        return 'push-delivery-order:'.hash('sha256', $scopeId.'|'.$fcmTokenId);
    }

    /**
     * Keep APNs phases for one dispatch on one provider ordering key.
     *
     * The queue job serializes provider submission per incident and device, so
     * a later definitive alarm is always submitted after an in-flight
     * preannouncement. APNs can then replace its pending older phase. Android
     * intentionally does not use collapse keys: FCM only retains four distinct
     * keys per device, which is unsafe for simultaneous critical dispatches.
     *
     * @param  array<string, string>  $data
     */
    public static function dispatchCollapseId(array $data): ?string
    {
        $dispatchId = $data['dispatch_id'] ?? null;
        if (! is_string($dispatchId) || ! Str::isUlid($dispatchId)) {
            return null;
        }

        $type = $data['type'] ?? null;
        $actionMode = $data['action_mode'] ?? null;
        $isDispatchPhase = $type === 'dispatch_request'
            || $type === 'incident_preannouncement'
            || ($type === 'dispatch_update' && in_array($actionMode, ['availability', 'attendance'], true))
            || ($type === 'dispatch_response_sync'
                && in_array($actionMode, ['availability', 'attendance', 'test_ack'], true));
        if (! $isDispatchPhase) {
            return null;
        }

        return 'dispatch-'.$dispatchId;
    }
}
