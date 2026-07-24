<?php

namespace App\Services;

use App\Exceptions\RetryableTestAlertException;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\SystemSetting;
use App\Models\TestAlertScheduleDelivery;
use App\Models\TestAlertScheduleRun;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TestAlertService
{
    private const SCHEDULE_RECOVERY_HOURS = 2;

    public const SCOPE_ALL_ONLINE = 'all_online';

    public const SCOPE_SELF = 'self';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly DispatchService $dispatchService,
        private readonly DispatchPushOutboxService $outbox,
        private readonly TestAlertMessageService $messageContent,
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
        return $this->sendResolved($actor, $scope);
    }

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
    private function sendResolved(
        User $actor,
        string $scope,
        ?string $fixedMessage = null,
    ): array {
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

        $failedSummary = null;
        try {
            $persisted = DB::transaction(function () use (
                $actor,
                $scope,
                $fixedMessage,
                $targets,
                $skippedUsers,
                &$failedSummary,
            ): array {
                User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $supersededDispatchIds = $this->expirePreviousTestAlerts($actor);
                $dispatch = $this->createDispatch($actor, $fixedMessage);
                $fanOut = $this->fanOut($dispatch, $targets);
                $summary = $this->summary(
                    $scope,
                    $fanOut['recipient_count'],
                    $fanOut['queued_token_count'],
                    $skippedUsers,
                    $fanOut['failed_user_count'],
                );

                if ($fanOut['recipient_count'] === 0) {
                    $failedSummary = $summary;

                    throw ValidationException::withMessages([
                        'recipients' => ['De proefalarmering kon voor geen enkele operator-app worden klaargezet.'],
                    ]);
                }

                $this->auditService->record(
                    'test_alert.sent',
                    $dispatch,
                    $actor,
                    $this->auditSummary($summary) + [
                        'selected_user_count' => $targets->count(),
                        'incident_id' => $dispatch->incident_id,
                    ],
                );

                return [
                    'dispatch' => $dispatch->refresh(),
                    'summary' => $summary,
                    'superseded_dispatch_ids' => $supersededDispatchIds,
                ];
            });
        } catch (ValidationException $exception) {
            if (is_array($failedSummary)) {
                $this->auditService->record(
                    'test_alert.not_sent',
                    DispatchRequest::class,
                    $actor,
                    $this->auditSummary($failedSummary) + [
                        'selected_user_count' => $targets->count(),
                    ],
                );
            }

            throw $exception;
        }

        $dispatchId = (string) $persisted['dispatch']->id;
        $this->afterCommit(function () use ($persisted, $dispatchId): void {
            foreach ($persisted['superseded_dispatch_ids'] as $supersededDispatchId) {
                try {
                    $superseded = DispatchRequest::query()->find($supersededDispatchId);
                    if ($superseded !== null) {
                        $this->dispatchService->broadcastDispatchChange($superseded, 'test_superseded');
                    }
                } catch (Throwable $exception) {
                    Log::warning('Superseded test alert broadcast lookup failed after commit.', [
                        'dispatch_request_id' => $supersededDispatchId,
                        'exception_class' => $exception::class,
                    ]);
                }
            }

            try {
                $dispatch = DispatchRequest::query()->find($dispatchId);
                if ($dispatch !== null) {
                    $this->dispatchService->broadcastDispatchChange($dispatch, 'test_sent');
                }
            } catch (Throwable $exception) {
                Log::warning('Test alert broadcast lookup failed after commit.', [
                    'dispatch_request_id' => $dispatchId,
                    'exception_class' => $exception::class,
                ]);
            }

            try {
                $this->outbox->flushPending(500, $dispatchId);
            } catch (Throwable $exception) {
                Log::warning('Test alert push outbox flush failed after commit.', [
                    'dispatch_request_id' => $dispatchId,
                    'exception_class' => $exception::class,
                ]);
            }
        });

        return [
            'dispatch' => $persisted['dispatch']->load(['incident', 'recipients.user']),
            'summary' => $persisted['summary'],
        ];
    }

    private function createDispatch(User $actor, ?string $fixedMessage = null): DispatchRequest
    {
        $notificationMessage = trim($fixedMessage ?? $this->messageContent->deliveredMessage());
        if ($notificationMessage === '') {
            $notificationMessage = TestAlertMessageService::DEFAULT_MESSAGE;
        }

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

        return DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
            'requested_by_email' => $actor->email,
            'target_team_id' => null,
            'status' => 'draft',
            'priority' => 'normal',
            'message' => $notificationMessage,
        ])->load('incident');
    }

    /**
     * @param  Collection<int, array{user: User, tokens: Collection<int, FcmToken>}>  $targets
     * @return array{recipient_count: int, queued_token_count: int, failed_user_count: int}
     */
    private function fanOut(
        DispatchRequest $dispatch,
        Collection $targets,
    ): array {
        $recipientCount = 0;
        $queuedTokenCount = 0;
        $failedUserCount = 0;
        $persistenceFailed = false;
        $incident = $dispatch->incident()->firstOrFail();
        $preparedTargets = [];

        foreach ($targets as $target) {
            $user = $target['user'];
            try {
                $recipient = DB::transaction(fn (): DispatchRecipient => DispatchRecipient::query()->create([
                    'dispatch_request_id' => $dispatch->id,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'response_status' => 'pending',
                    'notified_at' => now(),
                ]));
            } catch (Throwable $exception) {
                Log::warning('Test alert recipient could not be persisted.', [
                    'dispatch_request_id' => (string) $dispatch->id,
                    'user_id' => (string) $user->id,
                    'exception_class' => $exception::class,
                ]);
                $persistenceFailed = true;
                $failedUserCount++;

                continue;
            }
            $preparedTargets[] = [
                'recipient' => $recipient,
                'tokens' => $target['tokens'],
            ];
        }

        if ($preparedTargets === []) {
            if ($persistenceFailed) {
                throw new RetryableTestAlertException('Test alert recipient persistence failed.');
            }

            return [
                'recipient_count' => 0,
                'queued_token_count' => 0,
                'failed_user_count' => $failedUserCount,
            ];
        }

        $queuedAt = now();
        $dispatch->forceFill([
            'status' => 'sent',
            'sent_at' => $queuedAt,
            'send_status' => 'queued_for_push',
            'send_queued_at' => $queuedAt,
            'send_released_at' => $queuedAt,
        ])->save();
        $data = $this->notificationData($dispatch, $incident);
        foreach ($preparedTargets as $target) {
            /** @var DispatchRecipient $recipient */
            $recipient = $target['recipient'];
            $queuedForUser = 0;
            foreach ($target['tokens'] as $token) {
                try {
                    DB::transaction(fn () => $this->outbox->store(
                        dispatchRequestId: (string) $dispatch->id,
                        fcmTokenId: (string) $token->id,
                        messageType: 'dispatch_request',
                        title: 'D.I.S proefalarmering',
                        body: (string) $dispatch->message,
                        data: $data,
                    ));
                    $queuedForUser++;
                    $queuedTokenCount++;
                } catch (Throwable $exception) {
                    Log::warning('Test alert push outbox row could not be persisted.', [
                        'dispatch_request_id' => (string) $dispatch->id,
                        'fcm_token_id' => (string) $token->id,
                        'exception_class' => $exception::class,
                    ]);
                    $persistenceFailed = true;
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

        if ($recipientCount === 0 && $persistenceFailed) {
            throw new RetryableTestAlertException('Test alert push outbox persistence failed.');
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
        $actor->load(['fcmTokens' => fn ($tokens) => $tokens
            ->where('is_active', true)
            ->where('client_type', 'operator')]);

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
     * @return array{sent_users: int, skipped_users: int, failed_users: int, expired_users: int, failed_runs: int}
     */
    public function sendScheduled(): array
    {
        $failedRuns = 0;
        $runIds = TestAlertScheduleRun::query()
            ->whereIn('status', [
                TestAlertScheduleRun::STATUS_PENDING,
                TestAlertScheduleRun::STATUS_PROCESSING,
            ])
            ->oldest('scheduled_for')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $slot = $this->scheduledSlot();
        if ($slot !== null) {
            try {
                $run = $this->ensureScheduledRun($slot);
                $runIds[] = (string) $run->id;
            } catch (Throwable $exception) {
                $failedRuns++;
                Log::warning('Scheduled test alert run could not be persisted.', [
                    'run_key' => $this->scheduleRunKey($slot),
                    'exception_class' => $exception::class,
                ]);
            }
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $expired = 0;
        foreach (array_values(array_unique($runIds)) as $runId) {
            $expiryResult = $this->expireScheduledRunIfNeeded($runId);
            if ($expiryResult === null) {
                $failedRuns++;

                continue;
            }
            $expired += $expiryResult['expired_users'];
            if ($expiryResult['failed_run']) {
                $failedRuns++;
            }
            try {
                $runStatus = TestAlertScheduleRun::query()->whereKey($runId)->value('status');
            } catch (Throwable $exception) {
                $failedRuns++;
                Log::warning('Scheduled test alert run state could not be loaded.', [
                    'run_id' => $runId,
                    'exception_class' => $exception::class,
                ]);

                continue;
            }
            if (in_array($runStatus, [
                TestAlertScheduleRun::STATUS_COMPLETED,
                TestAlertScheduleRun::STATUS_EXPIRED,
                TestAlertScheduleRun::STATUS_FAILED,
            ], true)) {
                continue;
            }

            try {
                $this->initializeScheduledRun($runId);
            } catch (Throwable $exception) {
                $failedRuns++;
                Log::warning('Scheduled test alert run initialization failed and will be retried.', [
                    'run_id' => $runId,
                    'exception_class' => $exception::class,
                ]);

                continue;
            }

            try {
                $deliveryIds = TestAlertScheduleDelivery::query()
                    ->where('test_alert_schedule_run_id', $runId)
                    ->whereIn('status', [
                        TestAlertScheduleDelivery::STATUS_PENDING,
                        TestAlertScheduleDelivery::STATUS_FAILED,
                    ])
                    ->orderBy('user_id')
                    ->pluck('id');
            } catch (Throwable $exception) {
                $failedRuns++;
                Log::warning('Scheduled test alert delivery state could not be loaded.', [
                    'run_id' => $runId,
                    'exception_class' => $exception::class,
                ]);

                continue;
            }
            foreach ($deliveryIds as $deliveryId) {
                $outcome = $this->processScheduledDelivery((string) $deliveryId);
                if ($outcome === TestAlertScheduleDelivery::STATUS_SENT) {
                    $sent++;
                } elseif ($outcome === TestAlertScheduleDelivery::STATUS_SKIPPED) {
                    $skipped++;
                } elseif ($outcome === TestAlertScheduleDelivery::STATUS_FAILED) {
                    $failed++;
                } elseif ($outcome === TestAlertScheduleDelivery::STATUS_EXPIRED) {
                    $expired++;
                }
            }

            try {
                if ($this->finalizeScheduledRun($runId)) {
                    $failedRuns++;
                }
            } catch (Throwable $exception) {
                $failedRuns++;
                Log::warning('Scheduled test alert run finalization failed and will be retried.', [
                    'run_id' => $runId,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return [
            'sent_users' => $sent,
            'skipped_users' => $skipped,
            'failed_users' => $failed,
            'expired_users' => $expired,
            'failed_runs' => $failedRuns,
        ];
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
            'message' => $this->messageContent->configuredMessage(),
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
            'test_alert.message' => $message !== '' ? $message : TestAlertMessageService::DEFAULT_MESSAGE,
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

    private function scheduledSlot(): ?Carbon
    {
        $schedule = $this->schedule();
        if (! $schedule['enabled']) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'Europe/Amsterdam');
        $now = now()->setTimezone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $schedule['time'], 2));
        $daysSinceConfiguredDay = ($now->dayOfWeekIso - (int) $schedule['day_of_week'] + 7) % 7;
        $slot = $now->copy()
            ->startOfDay()
            ->subDays($daysSinceConfiguredDay)
            ->setTime($hour, $minute);
        if ($slot->greaterThan($now)) {
            $slot->subWeek();
        }
        if (! $now->lessThan($slot->copy()->addHours(self::SCHEDULE_RECOVERY_HOURS))) {
            return null;
        }

        return $slot;
    }

    private function ensureScheduledRun(Carbon $slot): TestAlertScheduleRun
    {
        $message = $this->messageContent->deliveredMessage();

        return TestAlertScheduleRun::query()->firstOrCreate(
            ['run_key' => $this->scheduleRunKey($slot)],
            [
                'scheduled_for' => $slot,
                'retry_until' => $slot->copy()->addHours(self::SCHEDULE_RECOVERY_HOURS),
                'message' => $message,
                'status' => TestAlertScheduleRun::STATUS_PENDING,
            ],
        );
    }

    private function initializeScheduledRun(string $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $run = TestAlertScheduleRun::query()->whereKey($runId)->lockForUpdate()->first();
            if ($run === null || $run->initialized_at !== null
                || in_array($run->status, [
                    TestAlertScheduleRun::STATUS_COMPLETED,
                    TestAlertScheduleRun::STATUS_EXPIRED,
                    TestAlertScheduleRun::STATUS_FAILED,
                ], true)) {
                return;
            }

            $userIds = User::query()
                ->where('account_status', 'active')
                ->where('push_enabled', true)
                ->whereHas('roles', fn ($roles) => $roles->where('roles.can_use_operator_app', true))
                ->whereHas('fcmTokens', fn ($tokens) => $tokens
                    ->where('is_active', true)
                    ->where('client_type', 'operator'))
                ->orderBy('id')
                ->pluck('id');
            foreach ($userIds as $userId) {
                TestAlertScheduleDelivery::query()->firstOrCreate([
                    'test_alert_schedule_run_id' => $run->id,
                    'user_id' => $userId,
                ], [
                    'status' => TestAlertScheduleDelivery::STATUS_PENDING,
                ]);
            }
            $run->forceFill([
                'status' => TestAlertScheduleRun::STATUS_PROCESSING,
                'target_count' => $userIds->count(),
                'initialized_at' => now(),
                'started_at' => $run->started_at ?? now(),
            ])->save();
        });
    }

    private function processScheduledDelivery(string $deliveryId): string
    {
        try {
            return DB::transaction(function () use ($deliveryId): string {
                $delivery = TestAlertScheduleDelivery::query()
                    ->whereKey($deliveryId)
                    ->lockForUpdate()
                    ->first();
                if ($delivery === null || in_array($delivery->status, [
                    TestAlertScheduleDelivery::STATUS_EXPIRED,
                    TestAlertScheduleDelivery::STATUS_SENT,
                    TestAlertScheduleDelivery::STATUS_SKIPPED,
                ], true)) {
                    return 'unchanged';
                }

                $delivery->load(['run', 'user.roles']);
                $user = $delivery->user;
                $run = $delivery->run;
                if ($run !== null
                    && ($run->status === TestAlertScheduleRun::STATUS_EXPIRED
                        || now()->greaterThanOrEqualTo($run->retry_until))) {
                    $delivery->forceFill([
                        'status' => TestAlertScheduleDelivery::STATUS_EXPIRED,
                        'last_error_code' => 'scheduled_run_expired',
                        'completed_at' => now(),
                    ])->save();

                    return TestAlertScheduleDelivery::STATUS_EXPIRED;
                }
                if ($run === null
                    || $user === null
                    || $user->account_status !== 'active'
                    || ! $user->push_enabled
                    || ! $user->canUseOperatorApp()) {
                    $delivery->forceFill([
                        'status' => TestAlertScheduleDelivery::STATUS_SKIPPED,
                        'attempts' => $delivery->attempts + 1,
                        'last_error_code' => 'scheduled_recipient_ineligible',
                        'last_attempted_at' => now(),
                        'completed_at' => now(),
                    ])->save();

                    return TestAlertScheduleDelivery::STATUS_SKIPPED;
                }

                $result = $this->sendResolved(
                    $user,
                    self::SCOPE_SELF,
                    (string) $run->message,
                );
                $delivery->forceFill([
                    'dispatch_request_id' => $result['dispatch']->id,
                    'status' => TestAlertScheduleDelivery::STATUS_SENT,
                    'attempts' => $delivery->attempts + 1,
                    'last_error_code' => null,
                    'last_attempted_at' => now(),
                    'completed_at' => now(),
                ])->save();

                return TestAlertScheduleDelivery::STATUS_SENT;
            });
        } catch (ValidationException) {
            $this->recordScheduledDeliveryFailure(
                $deliveryId,
                TestAlertScheduleDelivery::STATUS_SKIPPED,
                'scheduled_recipient_not_deliverable',
            );

            return TestAlertScheduleDelivery::STATUS_SKIPPED;
        } catch (Throwable $exception) {
            $this->recordScheduledDeliveryFailure(
                $deliveryId,
                TestAlertScheduleDelivery::STATUS_FAILED,
                'scheduled_delivery_failed',
            );
            Log::warning('Scheduled test alert delivery failed and will be retried.', [
                'delivery_id' => $deliveryId,
                'exception_class' => $exception::class,
            ]);

            return TestAlertScheduleDelivery::STATUS_FAILED;
        }
    }

    private function recordScheduledDeliveryFailure(
        string $deliveryId,
        string $status,
        string $errorCode,
    ): void {
        try {
            DB::transaction(function () use ($deliveryId, $status, $errorCode): void {
                $delivery = TestAlertScheduleDelivery::query()
                    ->whereKey($deliveryId)
                    ->lockForUpdate()
                    ->first();
                if ($delivery === null || in_array($delivery->status, [
                    TestAlertScheduleDelivery::STATUS_EXPIRED,
                    TestAlertScheduleDelivery::STATUS_SENT,
                    TestAlertScheduleDelivery::STATUS_SKIPPED,
                ], true)) {
                    return;
                }
                $delivery->forceFill([
                    'status' => $status,
                    'attempts' => $delivery->attempts + 1,
                    'last_error_code' => $errorCode,
                    'last_attempted_at' => now(),
                    'completed_at' => $status === TestAlertScheduleDelivery::STATUS_SKIPPED ? now() : null,
                ])->save();
            });
        } catch (Throwable $exception) {
            Log::warning('Scheduled test alert delivery outcome could not be persisted.', [
                'delivery_id' => $deliveryId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function finalizeScheduledRun(string $runId): bool
    {
        return DB::transaction(function () use ($runId): bool {
            $run = TestAlertScheduleRun::query()->whereKey($runId)->lockForUpdate()->first();
            if ($run === null || $run->initialized_at === null
                || in_array($run->status, [
                    TestAlertScheduleRun::STATUS_COMPLETED,
                    TestAlertScheduleRun::STATUS_EXPIRED,
                    TestAlertScheduleRun::STATUS_FAILED,
                ], true)) {
                return false;
            }

            $counts = TestAlertScheduleDelivery::query()
                ->where('test_alert_schedule_run_id', $run->id)
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $sent = (int) ($counts[TestAlertScheduleDelivery::STATUS_SENT] ?? 0);
            $skipped = (int) ($counts[TestAlertScheduleDelivery::STATUS_SKIPPED] ?? 0);
            $failed = (int) ($counts[TestAlertScheduleDelivery::STATUS_FAILED] ?? 0);
            $expired = (int) ($counts[TestAlertScheduleDelivery::STATUS_EXPIRED] ?? 0);
            $pending = (int) ($counts[TestAlertScheduleDelivery::STATUS_PENDING] ?? 0);
            $terminal = $failed === 0 && $pending === 0;
            $expiredRun = $terminal && $expired > 0;
            $failedRun = $terminal && ! $expiredRun && $sent === 0;
            $run->forceFill([
                'status' => $terminal
                    ? ($expiredRun
                        ? TestAlertScheduleRun::STATUS_EXPIRED
                        : ($failedRun
                            ? TestAlertScheduleRun::STATUS_FAILED
                            : TestAlertScheduleRun::STATUS_COMPLETED))
                    : TestAlertScheduleRun::STATUS_PROCESSING,
                'sent_count' => $sent,
                'skipped_count' => $skipped,
                'failed_count' => $failed,
                'expired_count' => $expired,
                'completed_at' => $terminal ? ($run->completed_at ?? now()) : null,
            ])->save();
            if (! $terminal) {
                return false;
            }

            if ($run->status === TestAlertScheduleRun::STATUS_COMPLETED) {
                $this->recordLastCompletedScheduleRun($run);
            } elseif ($run->status === TestAlertScheduleRun::STATUS_FAILED) {
                $this->recordScheduledRunFailure($run, 'scheduled_run_no_deliveries');
            }

            return $failedRun;
        });
    }

    /**
     * @return array{expired_users:int, failed_run:bool}|null
     */
    private function expireScheduledRunIfNeeded(string $runId): ?array
    {
        try {
            return DB::transaction(function () use ($runId): array {
                $run = TestAlertScheduleRun::query()->whereKey($runId)->lockForUpdate()->first();
                if ($run === null || in_array($run->status, [
                    TestAlertScheduleRun::STATUS_COMPLETED,
                    TestAlertScheduleRun::STATUS_EXPIRED,
                    TestAlertScheduleRun::STATUS_FAILED,
                ], true) || now()->lessThan($run->retry_until)) {
                    return ['expired_users' => 0, 'failed_run' => false];
                }

                if ($run->initialized_at === null) {
                    $run->forceFill([
                        'status' => TestAlertScheduleRun::STATUS_EXPIRED,
                        'completed_at' => now(),
                    ])->save();
                    $this->recordScheduledRunFailure($run, 'scheduled_run_expired_before_initialization');

                    return ['expired_users' => 0, 'failed_run' => true];
                }

                $expiredAt = now()->format('Y-m-d H:i:sP');
                $expired = TestAlertScheduleDelivery::query()
                    ->where('test_alert_schedule_run_id', $run->id)
                    ->whereIn('status', [
                        TestAlertScheduleDelivery::STATUS_PENDING,
                        TestAlertScheduleDelivery::STATUS_FAILED,
                    ])
                    ->update([
                        'status' => TestAlertScheduleDelivery::STATUS_EXPIRED,
                        'last_error_code' => 'scheduled_run_expired',
                        'completed_at' => $expiredAt,
                        'updated_at' => $expiredAt,
                    ]);

                return ['expired_users' => $expired, 'failed_run' => false];
            });
        } catch (Throwable $exception) {
            Log::warning('Scheduled test alert expiry could not be persisted.', [
                'run_id' => $runId,
                'exception_class' => $exception::class,
            ]);

            return null;
        }
    }

    private function recordLastCompletedScheduleRun(TestAlertScheduleRun $run): void
    {
        $lastRunAt = SystemSetting::string('test_alert.schedule_last_run_at');
        if ($lastRunAt !== null && Carbon::parse($lastRunAt)->greaterThanOrEqualTo($run->scheduled_for)) {
            return;
        }
        SystemSetting::query()->updateOrCreate(
            ['key' => 'test_alert.schedule_last_run_at'],
            [
                'value' => $this->localTimestamp($run->scheduled_for),
                'is_sensitive' => false,
                'updated_by' => null,
            ],
        );
    }

    private function recordScheduledRunFailure(TestAlertScheduleRun $run, string $failureCode): void
    {
        $this->auditService->record('test_alert.schedule_failed', $run, null, [
            'failure_code' => $failureCode,
            'scheduled_for' => $this->localTimestamp($run->scheduled_for),
            'retry_until' => $this->localTimestamp($run->retry_until),
            'target_count' => $run->target_count,
            'sent_count' => $run->sent_count,
            'skipped_count' => $run->skipped_count,
            'expired_count' => $run->expired_count,
        ]);
    }

    private function localTimestamp(DateTimeInterface $value): string
    {
        return Carbon::instance($value)
            ->setTimezone((string) config('app.timezone', 'Europe/Amsterdam'))
            ->format(DateTimeInterface::ATOM);
    }

    private function scheduleRunKey(Carbon $slot): string
    {
        return 'weekly:'.$slot->format('Y-m-d-H-i');
    }

    private function afterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
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

    /** @return list<string> */
    private function expirePreviousTestAlerts(User $actor): array
    {
        $superseded = [];
        $candidates = DispatchRequest::query()
            ->select(['id', 'incident_id'])
            ->where('requested_by', $actor->id)
            ->whereIn('status', ['draft', 'sent', 'escalated'])
            ->whereHas('incident', fn ($incident) => $incident
                ->where('is_test', true)
                ->whereNotIn('status', ['resolved', 'cancelled']))
            ->orderBy('incident_id')
            ->orderBy('id')
            ->get();
        foreach ($candidates as $candidate) {
            $incident = Incident::query()->whereKey($candidate->incident_id)->lockForUpdate()->first();
            $dispatch = DispatchRequest::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if ($incident === null || $dispatch === null
                || (string) $dispatch->requested_by !== (string) $actor->id
                || ! in_array($dispatch->status, ['draft', 'sent', 'escalated'], true)
                || ! $incident->is_test
                || in_array($incident->status, ['resolved', 'cancelled'], true)) {
                continue;
            }
            $dispatch->setRelation('incident', $incident);
            $dispatch->recipients()
                ->where('response_status', 'pending')
                ->update([
                    'response_status' => 'no_response',
                    'response_note' => 'Vervallen door nieuwe proefalarmering.',
                    'responded_at' => now(),
                ]);

            DispatchPushOutbox::query()
                ->where('dispatch_request_id', $dispatch->id)
                ->whereNull('delivered_at')
                ->whereNull('cancelled_at')
                ->update([
                    'cancelled_at' => now(),
                    'last_error_code' => 'superseded_by_test_alert',
                    'updated_at' => now(),
                ]);
            $dispatch->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $incident->update(['status' => 'cancelled', 'closed_at' => now()]);

            $this->auditService->record('test_alert.superseded', $dispatch, $actor, [
                'incident_id' => $dispatch->incident_id,
            ]);
            $superseded[] = (string) $dispatch->id;
        }

        return $superseded;
    }

    private function nextReference(): string
    {
        return 'TEST-'.now()->format('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    /** @return array<string, string> */
    private function notificationData(DispatchRequest $dispatch, Incident $incident): array
    {
        return [
            'type' => 'dispatch_request',
            'action_mode' => 'test_ack',
            'is_test' => 'true',
            'dispatch_id' => (string) $dispatch->id,
            'incident_id' => (string) $incident->id,
            'incident_reference' => (string) $incident->reference,
            'incident_title' => (string) $incident->title,
            'dispatch_message' => (string) $dispatch->message,
            'priority' => 'normal',
        ];
    }
}
