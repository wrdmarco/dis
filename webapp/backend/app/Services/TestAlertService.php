<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ApiDateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TestAlertService
{
    public const SCOPE_ALL_ONLINE = 'all_online';

    public const SCOPE_SELF = 'self';

    private const DEFAULT_MESSAGE = 'Dit is het wekelijkse proefalarm.';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly DispatchService $dispatchService,
    ) {}

    /**
     * @return array{
     *     dispatch: DispatchRequest,
     *     summary: array{
     *         scope: string,
     *         recipient_count: int,
     *         queued_token_count: int,
     *         skipped_user_count: int,
     *         failed_user_count: int
     *     }
     * }
     */
    public function send(User $actor, string $scope = self::SCOPE_SELF): array
    {
        if (! in_array($scope, [self::SCOPE_SELF, self::SCOPE_ALL_ONLINE], true)) {
            throw ValidationException::withMessages([
                'scope' => ['De gekozen proefalarmeringsscope is ongeldig.'],
            ]);
        }

        [$targets, $skippedUsers] = $scope === self::SCOPE_ALL_ONLINE
            ? $this->allOnlineTargets()
            : $this->selfTargets($actor);

        if ($targets->isEmpty()) {
            $summary = $this->summary($scope, 0, 0, $skippedUsers, 0);
            $this->auditService->record('test_alert.not_sent', DispatchRequest::class, $actor, $this->auditSummary($summary) + [
                'selected_user_count' => 0,
            ]);

            throw ValidationException::withMessages([
                'recipients' => ['Geen online operator-apps gevonden.'],
            ]);
        }

        $dispatch = $this->createDispatch($actor);
        $fanOut = $this->fanOut($dispatch, $targets);
        $summary = $this->summary(
            $scope,
            $fanOut['recipient_count'],
            $fanOut['queued_token_count'],
            $skippedUsers,
            $fanOut['failed_user_count'],
        );

        if ($fanOut['recipient_count'] === 0) {
            DB::transaction(function () use ($dispatch): void {
                $dispatch->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
                $dispatch->incident?->update([
                    'status' => 'cancelled',
                    'closed_at' => now(),
                ]);
            });
            $this->auditService->record('test_alert.not_sent', $dispatch, $actor, $this->auditSummary($summary) + [
                'selected_user_count' => $targets->count(),
                'incident_id' => $dispatch->incident_id,
            ]);
            $this->dispatchService->broadcastDispatchChange($dispatch->refresh(), 'test_not_sent');

            throw ValidationException::withMessages([
                'recipients' => ['De proefalarmering kon voor geen enkele operator-app worden klaargezet.'],
            ]);
        }

        $this->auditService->record('test_alert.sent', $dispatch, $actor, $this->auditSummary($summary) + [
            'selected_user_count' => $targets->count(),
            'incident_id' => $dispatch->incident_id,
        ]);
        $this->dispatchService->broadcastDispatchChange($dispatch->refresh(), 'test_sent');

        return [
            'dispatch' => $dispatch->load(['incident', 'recipients.user']),
            'summary' => $summary,
        ];
    }

    private function createDispatch(User $actor): DispatchRequest
    {
        return DB::transaction(function () use ($actor): DispatchRequest {
            $message = $this->message();
            $notificationMessage = $this->notificationMessage($message);
            $this->expirePreviousTestAlerts($actor);

            $incident = Incident::query()->create([
                'reference' => $this->nextReference(),
                'title' => 'Proefalarmering',
                'description' => $notificationMessage,
                'priority' => 'normal',
                'status' => 'active',
                'is_test' => true,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
                'created_by_email' => $actor->email,
                'coordinator_id' => $actor->id,
                'coordinator_name' => $actor->name,
                'coordinator_email' => $actor->email,
                'opened_at' => now(),
            ]);

            $dispatch = DispatchRequest::query()->create([
                'incident_id' => $incident->id,
                'requested_by' => $actor->id,
                'requested_by_name' => $actor->name,
                'requested_by_email' => $actor->email,
                'target_team_id' => null,
                'status' => 'sent',
                'priority' => 'normal',
                'message' => $notificationMessage,
                'sent_at' => now(),
            ]);

            return $dispatch->load('incident');
        });
    }

    /**
     * @param  Collection<int, array{user: User, tokens: Collection<int, FcmToken>}>  $targets
     * @return array{recipient_count: int, queued_token_count: int, failed_user_count: int}
     */
    private function fanOut(DispatchRequest $dispatch, Collection $targets): array
    {
        $recipientCount = 0;
        $queuedTokenCount = 0;
        $failedUserCount = 0;
        $incident = $dispatch->incident;

        foreach ($targets as $target) {
            $user = $target['user'];

            try {
                $recipient = DispatchRecipient::query()->create([
                    'dispatch_request_id' => $dispatch->id,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'response_status' => 'pending',
                    'notified_at' => now(),
                ]);
            } catch (Throwable $exception) {
                report($exception);
                $failedUserCount++;

                continue;
            }

            $queuedForUser = 0;
            foreach ($target['tokens'] as $token) {
                try {
                    SendFcmNotification::dispatch(
                        (string) $token->id,
                        'dispatch_request',
                        'D.I.S proefalarmering',
                        (string) $dispatch->message,
                        [
                            'type' => 'dispatch_request',
                            'action_mode' => 'test_ack',
                            'is_test' => 'true',
                            'dispatch_id' => (string) $dispatch->id,
                            'incident_id' => (string) $incident?->id,
                            'incident_reference' => (string) $incident?->reference,
                            'incident_title' => (string) $incident?->title,
                            'dispatch_message' => (string) $dispatch->message,
                            'priority' => 'normal',
                        ],
                        (string) $dispatch->id,
                    )->onQueue('push');
                    $queuedForUser++;
                    $queuedTokenCount++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            if ($queuedForUser === 0) {
                $failedUserCount++;
                $recipient->update([
                    'response_status' => 'no_response',
                    'response_note' => 'Proefalarmering kon niet worden klaargezet.',
                    'responded_at' => now(),
                ]);

                continue;
            }

            $recipientCount++;
        }

        return [
            'recipient_count' => $recipientCount,
            'queued_token_count' => $queuedTokenCount,
            'failed_user_count' => $failedUserCount,
        ];
    }

    /**
     * @return array{Collection<int, array{user: User, tokens: Collection<int, FcmToken>}>, int}
     */
    private function selfTargets(User $actor): array
    {
        $actor->load(['fcmTokens' => fn ($tokens) => $tokens->where('is_active', true)]);

        if (! $actor->push_enabled || $actor->fcmTokens->isEmpty()) {
            throw ValidationException::withMessages([
                'push' => ['De ingelogde gebruiker heeft geen actieve push-token. Open de operator-app en registreer pushmeldingen eerst.'],
            ]);
        }

        return [collect([['user' => $actor, 'tokens' => $actor->fcmTokens->values()]]), 0];
    }

    /**
     * @return array{Collection<int, array{user: User, tokens: Collection<int, FcmToken>}>, int}
     */
    private function allOnlineTargets(): array
    {
        $candidates = User::query()
            ->with(['fcmTokens' => fn ($tokens) => $tokens
                ->where('is_active', true)
                ->where('client_type', 'operator')])
            ->where('account_status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('roles.can_use_operator_app', true))
            ->get();

        $skippedUsers = 0;
        $targets = $candidates->map(function (User $user) use (&$skippedUsers): array|null {
            $onlineTokens = $user->fcmTokens
                ->filter(fn (FcmToken $token): bool => $token->is_online)
                ->values();

            if (! $user->push_enabled || $onlineTokens->isEmpty()) {
                $skippedUsers++;

                return null;
            }

            return ['user' => $user, 'tokens' => $onlineTokens];
        })->filter()->values();

        return [$targets, $skippedUsers];
    }

    /**
     * @return array{scope: string, recipient_count: int, queued_token_count: int, skipped_user_count: int, failed_user_count: int}
     */
    private function summary(
        string $scope,
        int $recipientCount,
        int $queuedTokenCount,
        int $skippedUserCount,
        int $failedUserCount,
    ): array {
        return [
            'scope' => $scope,
            'recipient_count' => $recipientCount,
            'queued_token_count' => $queuedTokenCount,
            'skipped_user_count' => $skippedUserCount,
            'failed_user_count' => $failedUserCount,
        ];
    }

    /**
     * Token values are sensitive, but this count is not. The audit key deliberately
     * describes devices so the central secret redactor does not hide the number.
     *
     * @param  array{scope: string, recipient_count: int, queued_token_count: int, skipped_user_count: int, failed_user_count: int}  $summary
     * @return array{scope: string, recipient_count: int, queued_device_count: int, skipped_user_count: int, failed_user_count: int}
     */
    private function auditSummary(array $summary): array
    {
        return [
            'scope' => $summary['scope'],
            'recipient_count' => $summary['recipient_count'],
            'queued_device_count' => $summary['queued_token_count'],
            'skipped_user_count' => $summary['skipped_user_count'],
            'failed_user_count' => $summary['failed_user_count'],
        ];
    }

    /**
     * @return array{sent_users: int, skipped_users: int}
     */
    public function sendScheduled(): array
    {
        if (! $this->scheduleDue()) {
            return ['sent_users' => 0, 'skipped_users' => 0];
        }

        $users = User::query()
            ->with(['roles', 'fcmTokens' => fn ($tokens) => $tokens->where('is_active', true)])
            ->where('account_status', 'active')
            ->where('push_enabled', true)
            ->whereHas('fcmTokens', fn ($tokens) => $tokens->where('is_active', true))
            ->get()
            ->filter(fn (User $user): bool => $user->canUseOperatorApp())
            ->values();

        $sent = 0;
        $skipped = 0;
        foreach ($users as $user) {
            try {
                $this->send($user);
                $sent++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return ['sent_users' => $sent, 'skipped_users' => $skipped];
    }

    /**
     * @return array{enabled: bool, day_of_week: int, time: string, message: string, last_run_at: string|null}
     */
    public function schedule(): array
    {
        return [
            'enabled' => SystemSetting::boolean('test_alert.schedule_enabled', false),
            'day_of_week' => SystemSetting::integer('test_alert.schedule_day_of_week', 1),
            'time' => SystemSetting::string('test_alert.schedule_time', '09:00') ?? '09:00',
            'message' => $this->message(),
            'last_run_at' => SystemSetting::string('test_alert.schedule_last_run_at'),
        ];
    }

    /**
     * @param  array{enabled: bool, day_of_week: int, time: string, message: string}  $data
     * @return array{enabled: bool, day_of_week: int, time: string, message: string, last_run_at: string|null}
     */
    public function updateSchedule(array $data, ?string $updatedBy): array
    {
        $message = trim($data['message']);
        $settings = [
            'test_alert.schedule_enabled' => (bool) $data['enabled'],
            'test_alert.schedule_day_of_week' => (int) $data['day_of_week'],
            'test_alert.schedule_time' => $data['time'],
            'test_alert.message' => $message !== '' ? $message : self::DEFAULT_MESSAGE,
        ];

        foreach ($settings as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'is_sensitive' => false,
                    'updated_by' => $updatedBy,
                ],
            );
        }

        return $this->schedule();
    }

    private function message(): string
    {
        return SystemSetting::string('test_alert.message', self::DEFAULT_MESSAGE) ?? self::DEFAULT_MESSAGE;
    }

    private function notificationMessage(string $message): string
    {
        $cleaned = preg_replace([
            '/\s*Bevestig deze proefalarmering met Ontvangen(?: in de app)?\.?/iu',
            '/\s*Bevestig ontvangst met de knop Ontvangen\.?/iu',
        ], '', $message);

        $cleaned = trim((string) $cleaned);

        return $cleaned !== '' ? $cleaned : self::DEFAULT_MESSAGE;
    }

    private function scheduleDue(): bool
    {
        $schedule = $this->schedule();
        if (! $schedule['enabled']) {
            return false;
        }

        $now = now();
        if ((int) $schedule['day_of_week'] !== $now->dayOfWeekIso || $schedule['time'] !== $now->format('H:i')) {
            return false;
        }

        $runKey = 'test-alert-schedule:'.$now->format('Y-m-d-H-i');
        if (! Cache::add($runKey, true, $now->copy()->addHours(2))) {
            return false;
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => 'test_alert.schedule_last_run_at'],
            [
                'value' => ApiDateTime::dateTime($now),
                'is_sensitive' => false,
                'updated_by' => null,
            ],
        );

        return true;
    }

    public function latestFor(User $actor): ?DispatchRequest
    {
        return DispatchRequest::query()
            ->with(['incident', 'recipients.user'])
            ->where('requested_by', $actor->id)
            ->whereHas('incident', fn ($incident) => $incident->where('is_test', true))
            ->latest()
            ->first();
    }

    private function expirePreviousTestAlerts(User $actor): void
    {
        DispatchRequest::query()
            ->with(['incident', 'recipients'])
            ->where('requested_by', $actor->id)
            ->whereIn('status', ['draft', 'sent', 'escalated'])
            ->whereHas('incident', fn ($incident) => $incident
                ->where('is_test', true)
                ->whereNotIn('status', ['resolved', 'cancelled']))
            ->get()
            ->each(function (DispatchRequest $dispatch) use ($actor): void {
                $dispatch->recipients()
                    ->where('response_status', 'pending')
                    ->update([
                        'response_status' => 'no_response',
                        'response_note' => 'Vervallen door nieuwe proefalarmering.',
                        'responded_at' => now(),
                    ]);

                $dispatch->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                $dispatch->incident?->update(['status' => 'cancelled', 'closed_at' => now()]);

                $this->auditService->record('test_alert.superseded', $dispatch, $actor, [
                    'incident_id' => $dispatch->incident_id,
                ]);
                $this->dispatchService->broadcastDispatchChange($dispatch->refresh(), 'test_superseded');
            });
    }

    private function nextReference(): string
    {
        return 'TEST-'.now()->format('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }
}
