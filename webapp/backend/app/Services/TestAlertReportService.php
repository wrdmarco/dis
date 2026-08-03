<?php

namespace App\Services;

use App\Models\DispatchRecipient;
use App\Models\TestAlertScheduleDelivery;
use App\Repositories\TestAlertReportRepository;
use App\Support\ApiDateTime;

final class TestAlertReportService
{
    public function __construct(private readonly TestAlertReportRepository $reports) {}

    /** @return array<string, mixed>|null */
    public function latestScheduled(): ?array
    {
        $run = $this->reports->latestScheduledRun();
        if ($run === null) {
            return null;
        }

        $dispatchIds = $run->deliveries
            ->pluck('dispatch_request_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
        $deviceCounts = $this->reports->deviceCountsByDispatch($dispatchIds);
        $counts = [
            'targeted' => max((int) $run->target_count, $run->deliveries->count()),
            'queued' => 0,
            'provider_accepted' => 0,
            'provider_pending' => 0,
            'provider_failed' => 0,
            'provider_unknown' => 0,
            'not_queued' => 0,
            'acknowledged' => 0,
            'unacknowledged' => 0,
        ];
        $recipients = [];

        foreach ($run->deliveries as $delivery) {
            $dispatch = $delivery->dispatchRequest;
            $recipient = $dispatch?->recipients->first(
                fn (DispatchRecipient $candidate): bool => $delivery->user_id === null
                    || (string) $candidate->user_id === (string) $delivery->user_id,
            ) ?? $dispatch?->recipients->first();
            $perDevice = $deviceCounts[(string) $delivery->dispatch_request_id] ?? [
                'total' => 0,
                'provider_accepted' => 0,
                'pending' => 0,
                'failed' => 0,
            ];
            $providerStatus = $this->providerStatus($delivery, $perDevice);
            $wasQueued = $delivery->status === TestAlertScheduleDelivery::STATUS_SENT;
            $wasAcknowledged = $wasQueued && $recipient?->response_status === 'accepted';

            if ($wasQueued) {
                $counts['queued']++;
                $counts[$wasAcknowledged ? 'acknowledged' : 'unacknowledged']++;
            } else {
                $counts['not_queued']++;
            }

            if (in_array($providerStatus, ['accepted', 'partial'], true)) {
                $counts['provider_accepted']++;
            } elseif ($providerStatus === 'pending') {
                $counts['provider_pending']++;
            } elseif ($providerStatus === 'failed') {
                $counts['provider_failed']++;
            } elseif ($providerStatus === 'not_recorded') {
                $counts['provider_unknown']++;
            }

            $recipients[] = [
                'id' => (string) $delivery->id,
                'user_id' => $delivery->user_id === null ? null : (string) $delivery->user_id,
                'user_name' => $recipient?->user_name
                    ?? $delivery->user?->name
                    ?? 'Verwijderde gebruiker',
                'user_email' => $recipient?->user_email ?? $delivery->user?->email,
                'schedule_status' => (string) $delivery->status,
                'provider_status' => $providerStatus,
                'response_status' => $recipient?->response_status,
                'device_counts' => $perDevice,
                'notified_at' => ApiDateTime::dateTime($recipient?->notified_at),
                'responded_at' => ApiDateTime::dateTime($recipient?->responded_at),
                'completed_at' => ApiDateTime::dateTime($delivery->completed_at),
                'detail' => $this->detail($delivery, $recipient, $providerStatus),
            ];
        }

        usort($recipients, static function (array $left, array $right): int {
            $priority = static fn (array $recipient): int => match (true) {
                in_array($recipient['provider_status'], ['failed', 'not_recorded', 'not_sent'], true) => 0,
                $recipient['response_status'] !== 'accepted' => 1,
                default => 2,
            };

            return [$priority($left), mb_strtolower($left['user_name'])]
                <=> [$priority($right), mb_strtolower($right['user_name'])];
        });

        return [
            'id' => (string) $run->id,
            'status' => (string) $run->status,
            'scheduled_for' => ApiDateTime::dateTime($run->scheduled_for),
            'started_at' => ApiDateTime::dateTime($run->started_at),
            'completed_at' => ApiDateTime::dateTime($run->completed_at),
            'counts' => $counts,
            'recipients' => $recipients,
        ];
    }

    /**
     * @param  array{total: int, provider_accepted: int, pending: int, failed: int}  $counts
     */
    private function providerStatus(TestAlertScheduleDelivery $delivery, array $counts): string
    {
        if ($delivery->status !== TestAlertScheduleDelivery::STATUS_SENT) {
            return 'not_sent';
        }
        if ($counts['total'] === 0) {
            return 'not_recorded';
        }
        if ($counts['provider_accepted'] > 0) {
            return $counts['provider_accepted'] === $counts['total'] ? 'accepted' : 'partial';
        }
        if ($counts['pending'] > 0) {
            return 'pending';
        }

        return 'failed';
    }

    private function detail(
        TestAlertScheduleDelivery $delivery,
        ?DispatchRecipient $recipient,
        string $providerStatus,
    ): ?string {
        if ($recipient?->response_status === 'accepted') {
            return 'Ontvangst bevestigd in de operator-app.';
        }
        if ($delivery->status === TestAlertScheduleDelivery::STATUS_SKIPPED) {
            return 'Geen actieve operator-app beschikbaar op het verzendmoment.';
        }
        if ($delivery->status === TestAlertScheduleDelivery::STATUS_EXPIRED) {
            return 'Het verzendvenster verliep voordat de melding kon worden klaargezet.';
        }
        if ($delivery->status === TestAlertScheduleDelivery::STATUS_FAILED) {
            return 'De melding kon nog niet worden klaargezet en wordt binnen het verzendvenster opnieuw geprobeerd.';
        }

        return match ($providerStatus) {
            'accepted' => 'De pushdienst accepteerde de melding; ontvangst is nog niet bevestigd.',
            'partial' => 'De pushdienst accepteerde ten minste één gekoppeld apparaat; ontvangst is nog niet bevestigd.',
            'pending' => 'De verzending naar de pushdienst is nog in behandeling.',
            'failed' => 'Voor geen gekoppeld apparaat is acceptatie door de pushdienst geregistreerd.',
            'not_recorded' => 'Het providerresultaat is niet (meer) beschikbaar; ontvangst blijft onbekend.',
            default => $recipient?->response_note,
        };
    }
}
