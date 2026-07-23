<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use Illuminate\Support\Collection;

final class IncidentTimelineResponsePresentation
{
    /**
     * @return array{
     *     response_label: string,
     *     occurred_at: mixed,
     *     actor: null,
     *     actor_name: null,
     *     description: string
     * }
     */
    public static function currentState(DispatchRecipient $recipient, DispatchRequest $dispatch): array
    {
        $recipientName = self::recipientName($recipient);
        $responseLabel = self::responseLabel($recipient->response_status);

        return [
            'response_label' => $responseLabel,
            'occurred_at' => $recipient->updated_at
                ?? $recipient->responded_at
                ?? $recipient->notified_at
                ?? $dispatch->sent_at
                ?? $dispatch->created_at,
            'actor' => null,
            'actor_name' => null,
            'description' => 'Actuele reactiestatus van '.$recipientName.': '.$responseLabel,
        ];
    }

    /**
     * @param  Collection<int, DispatchRequest>  $dispatches
     */
    public static function auditDescription(AuditLog $log, Collection $dispatches): ?string
    {
        if (! in_array($log->action, ['dispatch.responded', 'dispatch.recipient_response_overridden'], true)) {
            return null;
        }

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $response = is_string($metadata['response'] ?? null) ? $metadata['response'] : null;
        if ($response === null) {
            return null;
        }

        $dispatch = $dispatches->first(
            fn (DispatchRequest $candidate): bool => (string) $candidate->id === (string) $log->target_id,
        );
        if (! $dispatch instanceof DispatchRequest) {
            return null;
        }

        $recipient = self::auditRecipient($log, $dispatch, $metadata);
        if (! $recipient instanceof DispatchRecipient) {
            return null;
        }

        $recipientName = self::recipientName($recipient);
        $responseLabel = self::responseLabel($response);

        if ($log->action === 'dispatch.recipient_response_overridden') {
            return $response === 'pending'
                ? 'Reactie van '.$recipientName.' teruggezet naar '.$responseLabel
                : 'Reactie van '.$recipientName.' aangepast naar '.$responseLabel;
        }

        return 'Reactie van '.$recipientName.' vastgelegd: '.$responseLabel;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function auditRecipient(AuditLog $log, DispatchRequest $dispatch, array $metadata): ?DispatchRecipient
    {
        $recipientId = is_string($metadata['recipient_id'] ?? null) ? $metadata['recipient_id'] : null;
        if ($recipientId !== null) {
            $recipient = $dispatch->recipients->first(
                fn (DispatchRecipient $candidate): bool => (string) $candidate->id === $recipientId,
            );
            if ($recipient instanceof DispatchRecipient) {
                return $recipient;
            }
        }

        $userId = is_string($metadata['user_id'] ?? null)
            ? $metadata['user_id']
            : (is_string($log->actor_id) ? $log->actor_id : null);
        if ($userId === null) {
            return null;
        }

        $recipient = $dispatch->recipients->first(
            fn (DispatchRecipient $candidate): bool => (string) $candidate->user_id === $userId,
        );

        return $recipient instanceof DispatchRecipient ? $recipient : null;
    }

    private static function recipientName(DispatchRecipient $recipient): string
    {
        return $recipient->user?->name
            ?? (is_string($recipient->user_name) && $recipient->user_name !== ''
                ? $recipient->user_name
                : 'Verwijderde gebruiker');
    }

    private static function responseLabel(string $status): string
    {
        return match ($status) {
            'accepted' => 'Komt',
            'declined' => 'Komt niet',
            'no_response' => 'Geen reactie',
            default => 'Wacht op reactie',
        };
    }
}
