<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\PushDeliveryLog;
use App\Support\ApiDateTime;
use Illuminate\Database\Eloquent\Builder;

final class DeveloperDispatchDiagnosticsService
{
    private const ROW_LIMIT = 250;

    private const TIMELINE_LIMIT = 100;

    private const SAFE_PROVIDER_ERROR_CODES = [
        'BadCertificate',
        'BadCertificateEnvironment',
        'BadCollapseId',
        'BadDeviceToken',
        'BadExpirationDate',
        'BadMessageId',
        'BadPriority',
        'BadTopic',
        'DeviceTokenNotForTopic',
        'DuplicateHeaders',
        'ExpiredProviderToken',
        'Forbidden',
        'INTERNAL',
        'INVALID_ARGUMENT',
        'InvalidProviderToken',
        'InvalidPushType',
        'MissingDeviceToken',
        'MissingProviderToken',
        'MissingTopic',
        'NOT_FOUND',
        'PayloadEmpty',
        'PERMISSION_DENIED',
        'QUOTA_EXCEEDED',
        'SENDER_ID_MISMATCH',
        'ServiceUnavailable',
        'Shutdown',
        'THIRD_PARTY_AUTH_ERROR',
        'TooManyProviderTokenUpdates',
        'TooManyRequests',
        'TopicDisallowed',
        'UNAVAILABLE',
        'UNREGISTERED',
        'Unregistered',
    ];

    /**
     * Build a deliberately narrow operational diagnostic. This response must
     * never expose users, devices, provider identifiers, notification bodies,
     * payload data or FCM/APNs credentials.
     *
     * @return array<string, mixed>
     */
    public function build(DispatchRequest $dispatch): array
    {
        $recipientQuery = DispatchRecipient::query()->where('dispatch_request_id', $dispatch->id);
        $recipientTotal = (clone $recipientQuery)->count();
        $outboxQuery = DispatchPushOutbox::query()->where('dispatch_request_id', $dispatch->id);
        $outboxTotal = (clone $outboxQuery)->count();
        $outboxRows = (clone $outboxQuery)
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(self::ROW_LIMIT)
            ->get([
                'id',
                'message_type',
                'available_at',
                'queued_at',
                'delivered_at',
                'cancelled_at',
                'attempts',
                'last_attempted_at',
                'last_error_code',
                'created_at',
                'updated_at',
            ])
            ->reverse()
            ->values();
        $deliveryQuery = PushDeliveryLog::query()->where('dispatch_request_id', $dispatch->id);
        $deliveryTotal = (clone $deliveryQuery)->count();
        $deliveryRows = (clone $deliveryQuery)
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(self::ROW_LIMIT)
            ->get([
                'id',
                'message_type',
                'status',
                'error_code',
                'sent_at',
                'created_at',
            ])
            ->reverse()
            ->values();
        $incident = Incident::withTrashed()
            ->find($dispatch->incident_id, ['id', 'status', 'is_test', 'updated_at', 'deleted_at']);
        $dispatchTimeline = AuditLog::query()
            ->where('target_type', DispatchRequest::class)
            ->where('target_id', $dispatch->id)
            ->where('action', 'like', 'dispatch.%')
            ->latest('created_at')
            ->limit(self::TIMELINE_LIMIT)
            ->get(['action', 'metadata', 'created_at'])
            ->map(fn (AuditLog $entry): array => $this->timelineEntry($entry, 'dispatch'));
        $preannouncementTimeline = $incident === null
            ? collect()
            : AuditLog::query()
                ->where('target_type', Incident::class)
                ->where('target_id', $incident->id)
                ->where('action', 'incidents.preannouncement_sent')
                ->latest('created_at')
                ->limit(self::TIMELINE_LIMIT)
                ->get(['action', 'metadata', 'created_at'])
                ->filter(function (AuditLog $entry) use ($dispatch): bool {
                    $dispatchIds = data_get($entry->metadata, 'dispatch_ids', []);

                    return is_array($dispatchIds) && in_array((string) $dispatch->id, $dispatchIds, true);
                })
                ->map(fn (AuditLog $entry): array => $this->timelineEntry($entry, 'incident'));
        $timeline = $dispatchTimeline
            ->concat($preannouncementTimeline)
            ->sortBy('created_at')
            ->take(-self::TIMELINE_LIMIT)
            ->values();

        return [
            'generated_at' => ApiDateTime::now(),
            'dispatch' => [
                'id' => (string) $dispatch->id,
                'status' => (string) $dispatch->status,
                'created_at' => ApiDateTime::dateTime($dispatch->created_at),
                'updated_at' => ApiDateTime::dateTime($dispatch->updated_at),
                'sent_at' => ApiDateTime::dateTime($dispatch->sent_at),
                'cancelled_at' => ApiDateTime::dateTime($dispatch->cancelled_at),
            ],
            'incident' => $incident === null ? null : [
                'id' => (string) $incident->id,
                'status' => (string) $incident->status,
                'is_test' => (bool) $incident->is_test,
                'deleted' => $incident->deleted_at !== null,
                'updated_at' => ApiDateTime::dateTime($incident->updated_at),
            ],
            'recipients' => [
                'total' => $recipientTotal,
                'status_counts' => $this->groupCounts($recipientQuery, 'response_status'),
                'notified' => (clone $recipientQuery)->whereNotNull('notified_at')->count(),
                'responded' => (clone $recipientQuery)->whereNotNull('responded_at')->count(),
            ],
            'outbox' => [
                'total' => $outboxTotal,
                'state_counts' => $this->outboxStateCounts($outboxQuery),
                'rows_truncated' => $outboxTotal > $outboxRows->count(),
                'rows' => $outboxRows->map(fn (DispatchPushOutbox $row): array => [
                    'id' => (string) $row->id,
                    'message_type' => (string) $row->message_type,
                    'state' => $this->outboxState($row),
                    'attempts' => (int) $row->attempts,
                    'last_error_code' => $this->publicErrorCode($row->last_error_code),
                    'available_at' => ApiDateTime::dateTime($row->available_at),
                    'queued_at' => ApiDateTime::dateTime($row->queued_at),
                    'delivered_at' => ApiDateTime::dateTime($row->delivered_at),
                    'cancelled_at' => ApiDateTime::dateTime($row->cancelled_at),
                    'last_attempted_at' => ApiDateTime::dateTime($row->last_attempted_at),
                    'created_at' => ApiDateTime::dateTime($row->created_at),
                    'updated_at' => ApiDateTime::dateTime($row->updated_at),
                ])->values()->all(),
            ],
            'deliveries' => [
                'total' => $deliveryTotal,
                'status_counts' => $this->groupCounts($deliveryQuery, 'status'),
                'message_type_counts' => $this->groupCounts($deliveryQuery, 'message_type'),
                'rows_truncated' => $deliveryTotal > $deliveryRows->count(),
                'rows' => $deliveryRows->map(fn (PushDeliveryLog $row): array => [
                    'id' => (string) $row->id,
                    'message_type' => (string) $row->message_type,
                    'status' => (string) $row->status,
                    'error_code' => $this->publicErrorCode($row->error_code),
                    'sent_at' => ApiDateTime::dateTime($row->sent_at),
                    'created_at' => ApiDateTime::dateTime($row->created_at),
                ])->values()->all(),
            ],
            'timeline' => $timeline->all(),
        ];
    }

    /**
     * @param  Builder<*>  $query
     * @return array<string, int>
     */
    private function groupCounts(Builder $query, string $field): array
    {
        return (clone $query)
            ->select($field)
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy($field)
            ->orderBy($field)
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                trim((string) data_get($row, $field)) ?: 'unknown' => (int) data_get($row, 'aggregate', 0),
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function outboxStateCounts(Builder $query): array
    {
        $counts = [
            'cancelled' => (clone $query)
                ->whereNull('delivered_at')
                ->whereNotNull('cancelled_at')
                ->count(),
            'delivered' => (clone $query)->whereNotNull('delivered_at')->count(),
            'pending' => (clone $query)
                ->whereNull('delivered_at')
                ->whereNull('cancelled_at')
                ->whereNull('queued_at')
                ->count(),
            'queued' => (clone $query)
                ->whereNull('delivered_at')
                ->whereNull('cancelled_at')
                ->whereNotNull('queued_at')
                ->count(),
        ];

        return array_filter($counts, fn (int $count): bool => $count > 0);
    }

    private function outboxState(DispatchPushOutbox $row): string
    {
        return match (true) {
            $row->delivered_at !== null => 'delivered',
            $row->cancelled_at !== null => 'cancelled',
            $row->queued_at !== null => 'queued',
            default => 'pending',
        };
    }

    private function publicErrorCode(mixed $errorCode): ?string
    {
        if (! is_string($errorCode) || trim($errorCode) === '') {
            return null;
        }

        $errorCode = trim($errorCode);
        $knownCodes = [
            'delivery_exception',
            'delivery_retry_exhausted',
            'dispatch_not_deliverable',
            'fcm_error',
            'provider_rejected',
            'queue_unavailable',
            'token_inactive',
        ];

        if (in_array($errorCode, $knownCodes, true)
            || in_array($errorCode, self::SAFE_PROVIDER_ERROR_CODES, true)
            || preg_match('/^(?:apns|fcm)_http_[1-5][0-9]{2}$/', $errorCode) === 1) {
            return $errorCode;
        }

        return 'delivery_error';
    }

    /** @return array<string, mixed> */
    private function timelineEntry(AuditLog $entry, string $target): array
    {
        $counts = [];
        foreach (['recipient_count', 'recipient_users', 'queued_tokens'] as $key) {
            $value = data_get($entry->metadata, $key);
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $counts[$key] = (int) $value;
            }
        }

        return [
            'action' => (string) $entry->action,
            'target' => $target,
            'counts' => $counts,
            'created_at' => ApiDateTime::dateTime($entry->created_at),
        ];
    }
}
