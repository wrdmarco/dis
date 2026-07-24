<?php

namespace App\Services;

use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Support\ApiDateTime;

final class DispatchDeliveryStatusService
{
    /** @return array<string, mixed> */
    public function payload(DispatchRequest $dispatch): array
    {
        $rows = DispatchPushOutbox::query()
            ->where('dispatch_request_id', $dispatch->id)
            ->where('message_type', 'dispatch_request')
            ->get(['delivered_at', 'cancelled_at', 'queued_at']);
        $total = $rows->count();
        $accepted = $rows->whereNotNull('delivered_at')->count();
        $failed = $rows->whereNotNull('cancelled_at')->count();
        $pending = max(0, $total - $accepted - $failed);
        $state = match (true) {
            $total > 0 && $accepted === $total => 'sent',
            $accepted > 0 => 'partial',
            $pending > 0 => 'queued_for_push',
            default => 'failed',
        };

        return [
            'dispatch_id' => (string) $dispatch->id,
            'state' => $state,
            'queued_at' => ApiDateTime::dateTime($dispatch->send_queued_at),
            'released_at' => ApiDateTime::dateTime($dispatch->send_released_at),
            'device_counts' => [
                'total' => $total,
                'provider_accepted' => $accepted,
                'pending' => $pending,
                'failed' => $failed,
            ],
        ];
    }
}
