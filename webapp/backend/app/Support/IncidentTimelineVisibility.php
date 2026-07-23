<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class IncidentTimelineVisibility
{
    private const AUDIENCE_KEY = '_app_audience';

    private const USER_ID_KEY = '_app_user_id';

    private const AUDIENCE_EVERYONE = 'everyone';

    private const AUDIENCE_USER = 'user';

    private const AUDIENCE_STAFF = 'staff';

    /**
     * Audit actions that describe an incident- or dispatch-wide event.
     *
     * @var list<string>
     */
    private const EVERYONE_AUDIT_ACTIONS = [
        'incidents.updated',
        'incidents.deleted',
        'incidents.preannouncement_sent',
        'incidents.active_cancelled_notification_sent',
        'dispatch.sent',
        'dispatch.escalated',
        'dispatch.realerted',
        'location.sharing_stopped_for_incident',
    ];

    /**
     * Audit actions that may only be shown to the operator they concern.
     *
     * @var list<string>
     */
    private const USER_AUDIT_ACTIONS = [
        'dispatch.responded',
        'dispatch.recipient_response_overridden',
        'location.share_requested',
        'location.consent_enabled',
        'location.consent_declined',
        'location.consent_revoked',
        'location.sharing_stopped_for_user',
        'pilot_incident_report.prepared',
        'pilot_incident_report.opened_by_admin',
        'pilot_incident_report.submitted',
        'pilot_incident_report.submitted_by_admin',
        'pilot_incident_report.finalized',
        'pilot_incident_report.finalized_by_admin',
    ];

    /**
     * @return array{_app_audience: string, _app_user_id: null}
     */
    public static function everyone(): array
    {
        return [
            self::AUDIENCE_KEY => self::AUDIENCE_EVERYONE,
            self::USER_ID_KEY => null,
        ];
    }

    /**
     * @return array{_app_audience: string, _app_user_id: string|null}
     */
    public static function user(?string $userId): array
    {
        return [
            self::AUDIENCE_KEY => self::AUDIENCE_USER,
            self::USER_ID_KEY => self::validId($userId),
        ];
    }

    /**
     * @return array{_app_audience: string, _app_user_id: null}
     */
    public static function staff(): array
    {
        return [
            self::AUDIENCE_KEY => self::AUDIENCE_STAFF,
            self::USER_ID_KEY => null,
        ];
    }

    /**
     * @param  Collection<int, DispatchRequest>  $dispatches
     * @return array{_app_audience: string, _app_user_id: string|null}
     */
    public static function audit(AuditLog $log, Collection $dispatches): array
    {
        if (in_array($log->action, self::EVERYONE_AUDIT_ACTIONS, true)) {
            return self::everyone();
        }

        if (! in_array($log->action, self::USER_AUDIT_ACTIONS, true)) {
            return self::staff();
        }

        return self::user(self::auditUserId($log, $dispatches));
    }

    /**
     * Restrict the database query before its timeline limit is applied.
     *
     * @param  Builder<AuditLog>  $query
     * @param  list<string>  $recipientIds
     */
    public static function scopeAuditQueryForOperator(Builder $query, string $userId, array $recipientIds): void
    {
        $query->where(function (Builder $visible) use ($userId, $recipientIds): void {
            $visible
                ->whereIn('action', self::EVERYONE_AUDIT_ACTIONS)
                ->orWhere(function (Builder $personal) use ($userId, $recipientIds): void {
                    $personal
                        ->whereIn('action', self::USER_AUDIT_ACTIONS)
                        ->where(function (Builder $subject) use ($userId, $recipientIds): void {
                            $subject
                                ->where('metadata->user_id', $userId)
                                ->orWhere('metadata->submitted_for_user_id', $userId)
                                ->orWhere(function (Builder $legacySelf) use ($userId): void {
                                    $legacySelf
                                        ->whereIn('action', [
                                            'dispatch.responded',
                                            'location.consent_enabled',
                                            'location.consent_declined',
                                        ])
                                        ->where('actor_id', $userId);
                                });

                            if ($recipientIds !== []) {
                                $subject->orWhereIn('metadata->recipient_id', $recipientIds);
                            }
                        });
                });
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function visibleToOperator(array $item, string $userId): bool
    {
        $audience = $item[self::AUDIENCE_KEY] ?? null;
        if ($audience === self::AUDIENCE_EVERYONE) {
            return true;
        }

        return $audience === self::AUDIENCE_USER
            && self::validId($item[self::USER_ID_KEY] ?? null) === $userId;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function withoutInternalMetadata(array $item): array
    {
        unset($item[self::AUDIENCE_KEY], $item[self::USER_ID_KEY]);

        return $item;
    }

    /**
     * @param  Collection<int, DispatchRequest>  $dispatches
     */
    private static function auditUserId(AuditLog $log, Collection $dispatches): ?string
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        foreach (['user_id', 'submitted_for_user_id'] as $key) {
            $userId = self::validId($metadata[$key] ?? null);
            if ($userId !== null) {
                return $userId;
            }
        }

        $recipientId = self::validId($metadata['recipient_id'] ?? null);
        if ($recipientId !== null) {
            $dispatch = $dispatches->first(
                fn (DispatchRequest $candidate): bool => (string) $candidate->id === (string) $log->target_id,
            );
            $recipient = $dispatch?->recipients->first(
                fn (DispatchRecipient $candidate): bool => (string) $candidate->id === $recipientId,
            );
            if ($recipient instanceof DispatchRecipient) {
                return self::validId($recipient->user_id);
            }
        }

        if (in_array($log->action, ['dispatch.responded', 'location.consent_enabled', 'location.consent_declined'], true)) {
            return self::validId($log->actor_id);
        }

        return null;
    }

    private static function validId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
