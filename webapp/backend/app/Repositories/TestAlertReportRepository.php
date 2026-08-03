<?php

namespace App\Repositories;

use App\Models\DispatchPushOutbox;
use App\Models\TestAlertScheduleRun;

final class TestAlertReportRepository
{
    public function latestScheduledRun(): ?TestAlertScheduleRun
    {
        return TestAlertScheduleRun::query()
            ->with([
                'deliveries' => fn ($deliveries) => $deliveries
                    ->orderBy('created_at')
                    ->orderBy('id'),
                'deliveries.user:id,name,email',
                'deliveries.dispatchRequest.recipients',
            ])
            ->latest('scheduled_for')
            ->first();
    }

    /**
     * @param  list<string>  $dispatchIds
     * @return array<string, array{total: int, provider_accepted: int, pending: int, failed: int}>
     */
    public function deviceCountsByDispatch(array $dispatchIds): array
    {
        if ($dispatchIds === []) {
            return [];
        }

        return DispatchPushOutbox::query()
            ->select('dispatch_request_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS provider_accepted')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NULL AND cancelled_at IS NULL THEN 1 ELSE 0 END) AS pending')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NULL AND cancelled_at IS NOT NULL THEN 1 ELSE 0 END) AS failed')
            ->whereIn('dispatch_request_id', $dispatchIds)
            ->where('message_type', 'dispatch_request')
            ->groupBy('dispatch_request_id')
            ->get()
            ->mapWithKeys(static fn (DispatchPushOutbox $row): array => [
                (string) $row->dispatch_request_id => [
                    'total' => (int) $row->getAttribute('total'),
                    'provider_accepted' => (int) $row->getAttribute('provider_accepted'),
                    'pending' => (int) $row->getAttribute('pending'),
                    'failed' => (int) $row->getAttribute('failed'),
                ],
            ])
            ->all();
    }
}
