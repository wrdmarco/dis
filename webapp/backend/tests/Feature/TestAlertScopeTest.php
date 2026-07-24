<?php

namespace Tests\Feature;

use App\Events\DispatchChanged;
use App\Jobs\GenerateDispatchSpeechManifest;
use App\Jobs\SendFcmNotification;
use App\Models\AuditLog;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechModelInstallation;
use App\Models\SystemSetting;
use App\Models\TestAlertScheduleDelivery;
use App\Models\TestAlertScheduleRun;
use App\Models\User;
use App\Services\DispatchPushOutboxService;
use App\Services\SpeechTemplateService;
use App\Services\TestAlertService;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class TestAlertScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_scope_safely_defaults_to_self(): void
    {
        Queue::fake();
        $actor = $this->user('dispatcher@example.test', pushEnabled: true);
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);
        $actorToken = $this->token($actor, 'actor-device', lastSeenAt: now());

        $other = $this->user('other-operator@example.test', pushEnabled: true);
        $this->grant($other, [], operator: true, admin: false);
        $otherToken = $this->token($other, 'other-device', lastSeenAt: now());

        $response = $this->asWebClient($actor)->postJson('/api/test-alert');

        $response->assertCreated()
            ->assertJsonPath('meta.scope', 'self')
            ->assertJsonPath('meta.recipient_count', 1)
            ->assertJsonPath('meta.queued_token_count', 1)
            ->assertJsonPath('meta.skipped_user_count', 0)
            ->assertJsonPath('meta.failed_user_count', 0)
            ->assertJsonPath('data.recipients.0.user_id', $actor->id);

        $this->assertDatabaseHas('dispatch_recipients', ['user_id' => $actor->id]);
        $this->assertDatabaseMissing('dispatch_recipients', ['user_id' => $other->id]);
        $outbox = DispatchPushOutbox::query()
            ->where('dispatch_request_id', $response->json('data.id'))
            ->sole();
        $this->assertSame('immediate', $outbox->release_reason);
        $this->assertSame('test_ack', $outbox->data['action_mode'] ?? null);
        $this->assertSame('true', $outbox->data['is_test'] ?? null);
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $actorToken->id);
        Queue::assertNotPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $otherToken->id);

        $this->asWebClient($actor)
            ->getJson('/api/test-alert')
            ->assertOk()
            ->assertJsonPath('data.id', $response->json('data.id'));
    }

    public function test_manual_test_alert_uses_the_fixed_speech_template_and_ten_second_fallback(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        try {
            $actor = $this->user('fixed-test-alert@example.test', pushEnabled: true);
            $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);
            $token = $this->token($actor, 'fixed-test-alert', lastSeenAt: now());
            $this->enableSpeech($actor);
            $this->setting(
                'test_alert.message',
                'Dit is de vaste wekelijkse proefmelding. Bevestig deze proefalarmering met Ontvangen in de app.',
                $actor,
            );
            $this->setting('speech.templates.test_ack', [
                'Dit is een rustige proefalarmering.',
                'Open de D.I.S.-app en bevestig ontvangst.',
            ], $actor);

            $response = $this->asWebClient($actor)
                ->postJson('/api/test-alert')
                ->assertCreated()
                ->assertJsonPath('data.message', 'Dit is de vaste wekelijkse proefmelding.');

            $dispatch = DispatchRequest::query()->findOrFail($response->json('data.id'));
            $build = SpeechManifestBuild::query()
                ->where('dispatch_request_id', $dispatch->id)
                ->sole();
            $this->assertSame(SpeechTemplateService::PHASE_TEST_ACK, $build->phase);
            $this->assertSame([
                'Dit is de vaste wekelijkse proefmelding.',
                'Dit is een rustige proefalarmering.',
                'Open de D.I.S.-app en bevestig ontvangst.',
            ], $build->rendered_lines);
            $this->assertSame('preparing_speech', $dispatch->refresh()->send_status);
            $this->assertSame(10.0, now()->diffInSeconds($build->release_deadline));
            Queue::assertPushed(
                GenerateDispatchSpeechManifest::class,
                fn (GenerateDispatchSpeechManifest $job): bool => $job->buildId === $build->id,
            );
            Queue::assertNotPushed(SendFcmNotification::class);

            $outbox = DispatchPushOutbox::query()
                ->where('dispatch_request_id', $dispatch->id)
                ->sole();
            $this->assertSame($token->id, $outbox->fcm_token_id);
            $this->assertSame('speech_deadline', $outbox->release_reason);
            $this->assertSame(SpeechTemplateService::PHASE_TEST_ACK, $outbox->data['action_mode'] ?? null);
            $this->assertSame('true', $outbox->data['is_test'] ?? null);
            $this->assertArrayNotHasKey('speech_manifest_id', $outbox->data);

            Carbon::setTestNow(now()->addSeconds(10));
            app(DispatchPushOutboxService::class)->flushPending(100, (string) $dispatch->id);
            Queue::assertPushed(
                SendFcmNotification::class,
                fn (SendFcmNotification $job): bool => $job->dispatchPushOutboxId === $outbox->id
                    && ($job->data['action_mode'] ?? null) === SpeechTemplateService::PHASE_TEST_ACK
                    && ($job->data['is_test'] ?? null) === 'true'
                    && ! array_key_exists('speech_manifest_id', $job->data),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_weekly_schedule_reuses_the_fixed_template_without_manual_alert_fields(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        try {
            $operator = $this->operator('scheduled-fixed-test-alert@example.test');
            $this->token($operator, 'scheduled-fixed-test-alert', lastSeenAt: now());
            $this->enableSpeech($operator);
            foreach ([
                'test_alert.schedule_enabled' => true,
                'test_alert.schedule_day_of_week' => 1,
                'test_alert.schedule_time' => '09:00',
                'test_alert.message' => 'Vaste wekelijkse controle.',
                'speech.templates.test_ack' => [
                    'Dit is een proefalarmering.',
                    'Open de D.I.S.-app en bevestig ontvangst.',
                ],
            ] as $key => $value) {
                $this->setting($key, $value, $operator);
            }

            $result = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 1,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], $result);
            $dispatch = DispatchRequest::query()->sole();
            $this->assertSame($operator->id, $dispatch->requested_by);
            $this->assertSame('Vaste wekelijkse controle.', $dispatch->message);
            $build = SpeechManifestBuild::query()
                ->where('dispatch_request_id', $dispatch->id)
                ->sole();
            $this->assertSame(SpeechTemplateService::PHASE_TEST_ACK, $build->phase);
            $this->assertSame([
                'Vaste wekelijkse controle.',
                'Dit is een proefalarmering.',
                'Open de D.I.S.-app en bevestig ontvangst.',
            ], $build->rendered_lines);
            $this->assertDatabaseHas('dispatch_push_outbox', [
                'dispatch_request_id' => $dispatch->id,
                'release_reason' => 'speech_deadline',
            ]);
            Queue::assertPushed(
                GenerateDispatchSpeechManifest::class,
                fn (GenerateDispatchSpeechManifest $job): bool => $job->buildId === $build->id,
            );
            Queue::assertNotPushed(SendFcmNotification::class);

            Cache::flush();
            $this->assertSame(
                [
                    'sent_users' => 0,
                    'skipped_users' => 0,
                    'failed_users' => 0,
                    'expired_users' => 0,
                    'failed_runs' => 0,
                ],
                app(TestAlertService::class)->sendScheduled(),
            );
            $this->assertDatabaseCount('dispatch_requests', 1);
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame(TestAlertScheduleRun::STATUS_COMPLETED, $run->status);
            $this->assertSame(1, $run->sent_count);
            $this->assertSame(1, $run->target_count);
            $this->assertSame(1, TestAlertScheduleDelivery::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[DataProvider('recoverableWeeklyScheduleTimes')]
    public function test_weekly_schedule_recovers_a_missed_minute_with_exact_offset_timestamps(string $now): void
    {
        Queue::fake();
        DB::statement("SET TIME ZONE 'UTC'");
        Carbon::setTestNow(Carbon::parse($now, 'Europe/Amsterdam'));
        try {
            $suffix = str_replace([' ', ':'], '-', $now);
            $operator = $this->operator('scheduled-recovery-'.$suffix.'@example.test');
            $this->token($operator, 'scheduled-recovery-'.$suffix, lastSeenAt: now());
            $this->configureWeeklySchedule($operator);

            $result = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 1,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], $result);
            $expectedSlot = Carbon::parse('2026-07-20 09:00:00', 'Europe/Amsterdam');
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame('Y-m-d H:i:sP', $run->getDateFormat());
            $this->assertSame($expectedSlot->getTimestamp(), $run->scheduled_for->getTimestamp());
            $this->assertSame(
                $expectedSlot->copy()->addHours(2)->getTimestamp(),
                $run->retry_until->getTimestamp(),
            );
            $raw = DB::selectOne(
                'SELECT EXTRACT(EPOCH FROM scheduled_for) AS scheduled_epoch, '
                .'EXTRACT(EPOCH FROM retry_until) AS retry_epoch '
                .'FROM test_alert_schedule_runs WHERE id = ?',
                [$run->id],
            );
            $this->assertSame($expectedSlot->getTimestamp(), (int) $raw->scheduled_epoch);
            $this->assertSame(
                $expectedSlot->copy()->addHours(2)->getTimestamp(),
                (int) $raw->retry_epoch,
            );
            $delivery = TestAlertScheduleDelivery::query()->sole();
            $this->assertSame('Y-m-d H:i:sP', $delivery->getDateFormat());
            $this->assertSame(now()->getTimestamp(), $delivery->completed_at?->getTimestamp());
            $this->assertSame(
                $expectedSlot->format(DateTimeInterface::ATOM),
                SystemSetting::string('test_alert.schedule_last_run_at'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function recoverableWeeklyScheduleTimes(): iterable
    {
        yield 'one minute late' => ['2026-07-20 09:01:00'];
        yield 'one minute before recovery deadline' => ['2026-07-20 10:59:00'];
    }

    public function test_weekly_schedule_does_not_create_a_missed_run_at_the_recovery_deadline(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:00:00', 'Europe/Amsterdam'));
        try {
            $operator = $this->operator('scheduled-recovery-deadline@example.test');
            $this->token($operator, 'scheduled-recovery-deadline', lastSeenAt: now());
            $this->configureWeeklySchedule($operator);

            $this->assertSame([
                'sent_users' => 0,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], app(TestAlertService::class)->sendScheduled());
            $this->assertDatabaseCount('test_alert_schedule_runs', 0);
            $this->assertDatabaseCount('dispatch_requests', 0);
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_weekly_schedule_recovers_across_midnight_from_the_previous_configured_day(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 00:30:00', 'Europe/Amsterdam'));
        try {
            $operator = $this->operator('scheduled-midnight-recovery@example.test');
            $this->token($operator, 'scheduled-midnight-recovery', lastSeenAt: now());
            $this->configureWeeklySchedule($operator, dayOfWeek: 7, time: '23:30');

            $result = app(TestAlertService::class)->sendScheduled();

            $this->assertSame(1, $result['sent_users']);
            $this->assertSame(0, $result['failed_runs']);
            $expectedSlot = Carbon::parse('2026-07-19 23:30:00', 'Europe/Amsterdam');
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame($expectedSlot->getTimestamp(), $run->scheduled_for->getTimestamp());
            $this->assertSame(
                $expectedSlot->format(DateTimeInterface::ATOM),
                SystemSetting::string('test_alert.schedule_last_run_at'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_weekly_run_continues_after_one_user_crashes_and_retries_only_that_user_with_the_snapshot(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        try {
            $first = $this->operator('scheduled-first@example.test');
            $this->token($first, 'scheduled-first', lastSeenAt: now());
            $failing = $this->operator('scheduled-failing@example.test');
            $this->token($failing, 'scheduled-failing', lastSeenAt: now());
            $last = $this->operator('scheduled-last@example.test');
            $this->token($last, 'scheduled-last', lastSeenAt: now());
            $this->enableSpeech($first);
            foreach ([
                'test_alert.schedule_enabled' => true,
                'test_alert.schedule_day_of_week' => 1,
                'test_alert.schedule_time' => '09:00',
                'test_alert.message' => 'Oorspronkelijke wekelijkse controle.',
                'speech.templates.test_ack' => [
                    'Oorspronkelijke vaste proefzin.',
                    'Bevestig ontvangst in de D.I.S.-app.',
                ],
            ] as $key => $value) {
                $this->setting($key, $value, $first);
            }
            AuditLog::creating(function (AuditLog $audit) use ($failing): void {
                if ($audit->action === 'test_alert.sent' && $audit->actor_id === $failing->id) {
                    throw new RuntimeException('Simulated per-user crash after durable fan-out writes.');
                }
            });

            try {
                $firstAttempt = app(TestAlertService::class)->sendScheduled();
            } finally {
                AuditLog::flushEventListeners();
            }

            $this->assertSame([
                'sent_users' => 2,
                'skipped_users' => 0,
                'failed_users' => 1,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], $firstAttempt);
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame(TestAlertScheduleRun::STATUS_PROCESSING, $run->status);
            $this->assertSame(2, $run->sent_count);
            $this->assertSame(1, $run->failed_count);
            $this->assertNull($run->completed_at);
            $storedRun = DB::table('test_alert_schedule_runs')->where('id', $run->id)->first();
            $this->assertStringNotContainsString(
                'Oorspronkelijke wekelijkse controle',
                (string) $storedRun?->message,
            );
            $this->assertStringNotContainsString(
                'Oorspronkelijke vaste proefzin',
                (string) $storedRun?->speech_lines,
            );
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));
            $this->assertSame(2, DispatchRequest::query()->count());
            $this->assertSame(0, DispatchRequest::query()->where('requested_by', $failing->id)->count());

            $failedDelivery = TestAlertScheduleDelivery::query()
                ->where('user_id', $failing->id)
                ->sole();
            $this->assertSame(TestAlertScheduleDelivery::STATUS_FAILED, $failedDelivery->status);
            $this->assertSame(1, $failedDelivery->attempts);
            $this->assertSame('scheduled_delivery_failed', $failedDelivery->last_error_code);

            $this->setting('test_alert.message', 'Gewijzigde tekst die niet in deze run hoort.', $first);
            $this->setting('speech.templates.test_ack', [
                'Gewijzigde proefzin.',
            ], $first);
            Carbon::setTestNow(now()->addMinute());

            $retry = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 1,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], $retry);
            $this->assertSame(3, DispatchRequest::query()->count());
            foreach ([$first, $failing, $last] as $operator) {
                $this->assertSame(
                    1,
                    DispatchRequest::query()->where('requested_by', $operator->id)->count(),
                );
            }
            $this->assertSame(
                ['Oorspronkelijke wekelijkse controle.'],
                DispatchRequest::query()->distinct()->pluck('message')->all(),
            );
            foreach (SpeechManifestBuild::query()->get() as $build) {
                $this->assertSame([
                    'Oorspronkelijke wekelijkse controle.',
                    'Oorspronkelijke vaste proefzin.',
                    'Bevestig ontvangst in de D.I.S.-app.',
                ], $build->rendered_lines);
            }
            $run->refresh();
            $this->assertSame(TestAlertScheduleRun::STATUS_COMPLETED, $run->status);
            $this->assertSame(3, $run->sent_count);
            $this->assertSame(0, $run->failed_count);
            $this->assertNotNull($run->completed_at);
            $this->assertNotNull(SystemSetting::string('test_alert.schedule_last_run_at'));
            $this->assertSame(2, $failedDelivery->refresh()->attempts);
            $this->assertSame(TestAlertScheduleDelivery::STATUS_SENT, $failedDelivery->status);
        } finally {
            AuditLog::flushEventListeners();
            Carbon::setTestNow();
        }
    }

    public function test_weekly_run_retries_a_temporary_recipient_persistence_failure_without_duplicate_dispatch(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00', 'Europe/Amsterdam'));
        try {
            $operator = $this->operator('scheduled-recipient-retry@example.test');
            $this->token($operator, 'scheduled-recipient-retry', lastSeenAt: now());
            $this->configureWeeklySchedule($operator);
            $failedOnce = false;
            DispatchRecipient::creating(static function () use (&$failedOnce): void {
                if (! $failedOnce) {
                    $failedOnce = true;

                    throw new RuntimeException('Simulated temporary recipient persistence failure.');
                }
            });

            $firstAttempt = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 0,
                'skipped_users' => 0,
                'failed_users' => 1,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], $firstAttempt);
            $delivery = TestAlertScheduleDelivery::query()->sole();
            $this->assertSame(TestAlertScheduleDelivery::STATUS_FAILED, $delivery->status);
            $this->assertSame(1, $delivery->attempts);
            $this->assertDatabaseCount('dispatch_requests', 0);
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));

            Carbon::setTestNow(now()->addMinute());
            $retry = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 1,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], $retry);
            $this->assertDatabaseCount('dispatch_requests', 1);
            $this->assertDatabaseCount('dispatch_recipients', 1);
            $this->assertDatabaseCount('dispatch_push_outbox', 1);
            $this->assertSame(TestAlertScheduleDelivery::STATUS_SENT, $delivery->refresh()->status);
            $this->assertSame(2, $delivery->attempts);
            $this->assertNotNull(SystemSetting::string('test_alert.schedule_last_run_at'));
        } finally {
            DispatchRecipient::flushEventListeners();
            Carbon::setTestNow();
        }
    }

    public function test_weekly_run_retries_a_temporary_outbox_persistence_failure_without_duplicate_dispatch(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00', 'Europe/Amsterdam'));
        try {
            $operator = $this->operator('scheduled-outbox-retry@example.test');
            $this->token($operator, 'scheduled-outbox-retry', lastSeenAt: now());
            $this->configureWeeklySchedule($operator);
            $failedOnce = false;
            DispatchPushOutbox::creating(static function () use (&$failedOnce): void {
                if (! $failedOnce) {
                    $failedOnce = true;

                    throw new RuntimeException('Simulated temporary outbox persistence failure.');
                }
            });

            $firstAttempt = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 0,
                'skipped_users' => 0,
                'failed_users' => 1,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], $firstAttempt);
            $delivery = TestAlertScheduleDelivery::query()->sole();
            $this->assertSame(TestAlertScheduleDelivery::STATUS_FAILED, $delivery->status);
            $this->assertSame(1, $delivery->attempts);
            $this->assertDatabaseCount('dispatch_requests', 0);
            $this->assertDatabaseCount('dispatch_recipients', 0);
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));

            Carbon::setTestNow(now()->addMinute());
            $retry = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 1,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], $retry);
            $this->assertDatabaseCount('dispatch_requests', 1);
            $this->assertDatabaseCount('dispatch_recipients', 1);
            $this->assertDatabaseCount('dispatch_push_outbox', 1);
            $this->assertSame(TestAlertScheduleDelivery::STATUS_SENT, $delivery->refresh()->status);
            $this->assertSame(2, $delivery->attempts);
            $this->assertNotNull(SystemSetting::string('test_alert.schedule_last_run_at'));
        } finally {
            DispatchPushOutbox::flushEventListeners();
            Carbon::setTestNow();
        }
    }

    public function test_weekly_run_survives_target_snapshot_crash_and_resumes_after_the_scheduled_minute(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        try {
            $operator = $this->operator('scheduled-initialization-retry@example.test');
            $this->token($operator, 'scheduled-initialization-retry', lastSeenAt: now());
            foreach ([
                'test_alert.schedule_enabled' => true,
                'test_alert.schedule_day_of_week' => 1,
                'test_alert.schedule_time' => '09:00',
                'test_alert.message' => 'Duurzaam hervat proefalarm.',
            ] as $key => $value) {
                $this->setting($key, $value, $operator);
            }
            TestAlertScheduleDelivery::creating(static function (): void {
                throw new RuntimeException('Simulated target snapshot crash.');
            });

            try {
                $crashed = app(TestAlertService::class)->sendScheduled();
            } finally {
                TestAlertScheduleDelivery::flushEventListeners();
            }

            $this->assertSame([
                'sent_users' => 0,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 1,
            ], $crashed);
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame(TestAlertScheduleRun::STATUS_PENDING, $run->status);
            $this->assertNull($run->initialized_at);
            $this->assertDatabaseCount('test_alert_schedule_deliveries', 0);
            $this->assertDatabaseCount('dispatch_requests', 0);
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));

            Carbon::setTestNow(now()->addMinute());
            $resumed = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 1,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 0,
            ], $resumed);
            $this->assertDatabaseCount('dispatch_requests', 1);
            $this->assertSame(TestAlertScheduleRun::STATUS_COMPLETED, $run->refresh()->status);
            $this->assertNotNull(SystemSetting::string('test_alert.schedule_last_run_at'));
        } finally {
            TestAlertScheduleDelivery::flushEventListeners();
            Carbon::setTestNow();
        }
    }

    public function test_uninitialized_weekly_run_reports_and_audits_failure_when_its_recovery_window_expires(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00', 'Europe/Amsterdam'));
        try {
            $operator = $this->operator('scheduled-uninitialized-expiry@example.test');
            $this->token($operator, 'scheduled-uninitialized-expiry', lastSeenAt: now());
            $this->configureWeeklySchedule($operator);
            TestAlertScheduleDelivery::creating(static function (): void {
                throw new RuntimeException('Simulated persistent target snapshot failure.');
            });

            $initializationFailure = app(TestAlertService::class)->sendScheduled();

            $this->assertSame(1, $initializationFailure['failed_runs']);
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame(TestAlertScheduleRun::STATUS_PENDING, $run->status);
            $this->assertSame(0, $run->target_count);
            $this->assertNull($run->initialized_at);
            $this->assertDatabaseCount('audit_logs', 0);

            Carbon::setTestNow(now()->addHours(2));
            $expired = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 0,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 1,
            ], $expired);
            $run->refresh();
            $this->assertSame(TestAlertScheduleRun::STATUS_EXPIRED, $run->status);
            $this->assertSame(0, $run->target_count);
            $this->assertSame(0, $run->failed_count);
            $this->assertNotNull($run->completed_at);
            $audit = AuditLog::query()->where('action', 'test_alert.schedule_failed')->sole();
            $this->assertSame($run->id, $audit->target_id);
            $this->assertSame(
                'scheduled_run_expired_before_initialization',
                $audit->metadata['failure_code'] ?? null,
            );
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));
            $this->assertDatabaseCount('dispatch_requests', 0);
        } finally {
            TestAlertScheduleDelivery::flushEventListeners();
            Carbon::setTestNow();
        }
    }

    public function test_weekly_run_without_targets_is_audited_as_failed_and_does_not_advance_last_run(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00', 'Europe/Amsterdam'));
        try {
            $settingsActor = $this->user('scheduled-no-targets-settings@example.test');
            $this->configureWeeklySchedule($settingsActor);

            $result = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 0,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 1,
            ], $result);
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame(TestAlertScheduleRun::STATUS_FAILED, $run->status);
            $this->assertSame(0, $run->target_count);
            $this->assertSame(0, $run->sent_count);
            $this->assertNotNull($run->completed_at);
            $audit = AuditLog::query()->where('action', 'test_alert.schedule_failed')->sole();
            $this->assertSame('scheduled_run_no_deliveries', $audit->metadata['failure_code'] ?? null);
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));
            Queue::assertNothingPushed();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_weekly_run_with_only_skipped_targets_is_failed_and_does_not_advance_last_run(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00', 'Europe/Amsterdam'));
        try {
            $operator = $this->operator('scheduled-all-skipped@example.test');
            $this->token($operator, 'scheduled-all-skipped', lastSeenAt: now());
            $this->configureWeeklySchedule($operator);
            TestAlertScheduleDelivery::created(static function (TestAlertScheduleDelivery $delivery): void {
                User::query()->whereKey($delivery->user_id)->update(['push_enabled' => false]);
            });

            $result = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 0,
                'skipped_users' => 1,
                'failed_users' => 0,
                'expired_users' => 0,
                'failed_runs' => 1,
            ], $result);
            $delivery = TestAlertScheduleDelivery::query()->sole();
            $this->assertSame(TestAlertScheduleDelivery::STATUS_SKIPPED, $delivery->status);
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame(TestAlertScheduleRun::STATUS_FAILED, $run->status);
            $this->assertSame(1, $run->target_count);
            $this->assertSame(0, $run->sent_count);
            $this->assertSame(1, $run->skipped_count);
            $audit = AuditLog::query()->where('action', 'test_alert.schedule_failed')->sole();
            $this->assertSame('scheduled_run_no_deliveries', $audit->metadata['failure_code'] ?? null);
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));
            $this->assertDatabaseCount('dispatch_requests', 0);
            Queue::assertNothingPushed();
        } finally {
            TestAlertScheduleDelivery::flushEventListeners();
            Carbon::setTestNow();
        }
    }

    public function test_weekly_run_marks_remaining_users_expired_after_the_existing_two_hour_horizon(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        try {
            $operator = $this->operator('scheduled-expiry@example.test');
            $this->token($operator, 'scheduled-expiry', lastSeenAt: now());
            foreach ([
                'test_alert.schedule_enabled' => true,
                'test_alert.schedule_day_of_week' => 1,
                'test_alert.schedule_time' => '09:00',
                'test_alert.message' => 'Proefalarm met herstelvenster.',
            ] as $key => $value) {
                $this->setting($key, $value, $operator);
            }
            AuditLog::creating(static function (AuditLog $audit): void {
                if ($audit->action === 'test_alert.sent') {
                    throw new RuntimeException('Simulated persistent delivery crash.');
                }
            });

            try {
                $firstAttempt = app(TestAlertService::class)->sendScheduled();
            } finally {
                AuditLog::flushEventListeners();
            }
            $this->assertSame(1, $firstAttempt['failed_users']);
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));
            $this->assertDatabaseCount('dispatch_requests', 0);

            Carbon::setTestNow(now()->addHours(2));
            $expiredAttempt = app(TestAlertService::class)->sendScheduled();

            $this->assertSame([
                'sent_users' => 0,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 1,
                'failed_runs' => 0,
            ], $expiredAttempt);
            $delivery = TestAlertScheduleDelivery::query()->sole();
            $this->assertSame(TestAlertScheduleDelivery::STATUS_EXPIRED, $delivery->status);
            $this->assertSame('scheduled_run_expired', $delivery->last_error_code);
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame(TestAlertScheduleRun::STATUS_EXPIRED, $run->status);
            $this->assertSame(1, $run->expired_count);
            $this->assertSame(0, $run->sent_count);
            $this->assertNotNull($run->completed_at);
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));
            $this->assertDatabaseCount('dispatch_requests', 0);
            Queue::assertNothingPushed();
        } finally {
            AuditLog::flushEventListeners();
            Carbon::setTestNow();
        }
    }

    public function test_weekly_run_does_not_start_another_delivery_after_the_recovery_horizon_passes(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        try {
            $first = $this->operator('scheduled-before-horizon@example.test');
            $this->token($first, 'scheduled-before-horizon', lastSeenAt: now());
            $second = $this->operator('scheduled-after-horizon@example.test');
            $this->token($second, 'scheduled-after-horizon', lastSeenAt: now());
            foreach ([
                'test_alert.schedule_enabled' => true,
                'test_alert.schedule_day_of_week' => 1,
                'test_alert.schedule_time' => '09:00',
                'test_alert.message' => 'Proefalarm rond de herstelgrens.',
            ] as $key => $value) {
                $this->setting($key, $value, $first);
            }
            $advanced = false;
            AuditLog::created(static function (AuditLog $audit) use (&$advanced): void {
                if (! $advanced && $audit->action === 'test_alert.sent') {
                    $advanced = true;
                    Carbon::setTestNow(Carbon::parse('2026-07-20 11:00:00'));
                }
            });

            try {
                $result = app(TestAlertService::class)->sendScheduled();
            } finally {
                AuditLog::flushEventListeners();
            }

            $this->assertSame([
                'sent_users' => 1,
                'skipped_users' => 0,
                'failed_users' => 0,
                'expired_users' => 1,
                'failed_runs' => 0,
            ], $result);
            $this->assertDatabaseCount('dispatch_requests', 1);
            $this->assertSame(1, TestAlertScheduleDelivery::query()
                ->where('status', TestAlertScheduleDelivery::STATUS_SENT)
                ->count());
            $this->assertSame(1, TestAlertScheduleDelivery::query()
                ->where('status', TestAlertScheduleDelivery::STATUS_EXPIRED)
                ->where('last_error_code', 'scheduled_run_expired')
                ->count());
            $run = TestAlertScheduleRun::query()->sole();
            $this->assertSame(TestAlertScheduleRun::STATUS_EXPIRED, $run->status);
            $this->assertSame(1, $run->sent_count);
            $this->assertSame(1, $run->expired_count);
            $this->assertNull(SystemSetting::string('test_alert.schedule_last_run_at'));
            Queue::assertPushed(SendFcmNotification::class, 1);
        } finally {
            AuditLog::flushEventListeners();
            Carbon::setTestNow();
        }
    }

    public function test_audit_failure_rolls_back_the_entire_test_alert_and_never_broadcasts_phantom_state(): void
    {
        Queue::fake();
        $actor = $this->user('atomic-test-alert@example.test', pushEnabled: true);
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);
        $this->token($actor, 'atomic-test-alert', lastSeenAt: now());
        $previous = app(TestAlertService::class)->send($actor)['dispatch'];
        $previousOutbox = DispatchPushOutbox::query()
            ->where('dispatch_request_id', $previous->id)
            ->sole();
        Event::fake([DispatchChanged::class]);
        AuditLog::creating(static function (AuditLog $audit): void {
            if ($audit->action === 'test_alert.sent') {
                throw new RuntimeException('Simulated audit persistence outage.');
            }
        });

        try {
            app(TestAlertService::class)->send($actor);
            $this->fail('The simulated audit outage must abort the test alert.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated audit persistence outage.', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('dispatch_requests', 1);
        $this->assertDatabaseCount('dispatch_recipients', 1);
        $this->assertDatabaseCount('dispatch_push_outbox', 1);
        $this->assertDatabaseCount('speech_manifest_builds', 0);
        $this->assertSame('sent', $previous->refresh()->status);
        $this->assertSame('active', $previous->incident()->firstOrFail()->status);
        $this->assertNull($previousOutbox->refresh()->cancelled_at);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'test_alert.sent']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'test_alert.superseded']);
        Event::assertNotDispatched(DispatchChanged::class);
        Queue::assertPushed(SendFcmNotification::class, 1);
    }

    public function test_outer_transaction_crash_discards_test_alert_state_and_after_commit_side_effects(): void
    {
        Queue::fake();
        Event::fake([DispatchChanged::class]);
        $actor = $this->user('outer-transaction-test-alert@example.test', pushEnabled: true);
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);
        $this->token($actor, 'outer-transaction-test-alert', lastSeenAt: now());

        DB::beginTransaction();
        try {
            app(TestAlertService::class)->send($actor);
            $this->assertDatabaseCount('dispatch_requests', 1);
            $this->assertDatabaseCount('dispatch_push_outbox', 1);
            $this->assertDatabaseHas('audit_logs', ['action' => 'test_alert.sent']);
            Event::assertNotDispatched(DispatchChanged::class);
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertDatabaseCount('dispatch_recipients', 0);
        $this->assertDatabaseCount('dispatch_push_outbox', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'test_alert.sent']);
        Event::assertNotDispatched(DispatchChanged::class);
        Queue::assertNothingPushed();
    }

    public function test_test_alert_push_fails_open_when_the_speech_runtime_is_misconfigured(): void
    {
        Queue::fake();
        $actor = $this->user('misconfigured-test-alert@example.test', pushEnabled: true);
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);
        $token = $this->token($actor, 'misconfigured-test-alert', lastSeenAt: now());
        $this->setting('speech.enabled', true, $actor);
        $this->setting('speech.model_id', 'missing-model', $actor);

        $response = $this->asWebClient($actor)
            ->postJson('/api/test-alert')
            ->assertCreated();

        $dispatch = DispatchRequest::query()->findOrFail($response->json('data.id'));
        $this->assertSame('queued_for_push', $dispatch->send_status);
        $this->assertNull($dispatch->send_release_deadline);
        $this->assertDatabaseMissing('speech_manifest_builds', [
            'dispatch_request_id' => $dispatch->id,
        ]);
        $outbox = DispatchPushOutbox::query()
            ->where('dispatch_request_id', $dispatch->id)
            ->sole();
        $this->assertSame('immediate', $outbox->release_reason);
        Queue::assertPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->fcmTokenId === $token->id
                && ! array_key_exists('speech_manifest_id', $job->data),
        );
    }

    public function test_all_online_only_targets_reachable_active_operator_app_users(): void
    {
        $this->freezeSecond();
        Queue::fake();
        $actor = $this->user('coordinator@example.test');
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);

        $firstEligible = $this->operator('eligible-one@example.test');
        $firstToken = $this->token($firstEligible, 'eligible-one', lastSeenAt: now());
        $firstEligible->statuses()->create([
            'status' => 'unavailable',
            'is_available' => false,
            'is_system_applied' => false,
            'effective_at' => now(),
        ]);
        $secondEligible = $this->operator('eligible-two@example.test');
        $secondToken = $this->token($secondEligible, 'eligible-two', lastSeenAt: now()->subMinute());
        $secondDeviceToken = $this->token($secondEligible, 'eligible-two-second-device', lastSeenAt: now()->subMinutes(2));
        $staleSecondToken = $this->token(
            $secondEligible,
            'eligible-two-stale-device',
            lastSeenAt: now()->subMinutes(FcmToken::onlineThresholdMinutes() + 1),
        );
        $adminSecondToken = $this->token($secondEligible, 'eligible-two-admin-device', clientType: 'admin', lastSeenAt: now());

        $pushDisabled = $this->operator('push-disabled@example.test', pushEnabled: false);
        $this->token($pushDisabled, 'push-disabled', lastSeenAt: now());
        $offline = $this->operator('offline@example.test');
        $this->token($offline, 'offline', lastSeenAt: now()->subMinutes(FcmToken::onlineThresholdMinutes() + 1));
        $thresholdBoundary = $this->operator('threshold-boundary@example.test');
        $this->token($thresholdBoundary, 'threshold-boundary', lastSeenAt: now()->subMinutes(FcmToken::onlineThresholdMinutes()));
        $withoutToken = $this->operator('without-token@example.test');
        $adminDeviceOnly = $this->operator('admin-device-only@example.test');
        $this->token($adminDeviceOnly, 'admin-device-only', clientType: 'admin', lastSeenAt: now());

        $nonOperator = $this->user('non-operator@example.test', pushEnabled: true);
        $this->grant($nonOperator, [], operator: false, admin: true);
        $this->token($nonOperator, 'non-operator', lastSeenAt: now());
        $inactive = $this->operator('inactive@example.test', accountStatus: 'disabled');
        $this->token($inactive, 'inactive', lastSeenAt: now());
        $storeReview = $this->operator('store-review@example.test', accountStatus: 'store_review');
        $this->token($storeReview, 'store-review', lastSeenAt: now());
        $deleted = $this->operator('deleted@example.test');
        $this->token($deleted, 'deleted', lastSeenAt: now());
        $deleted->delete();

        $response = $this->asWebClient($actor)->postJson('/api/test-alert', ['scope' => 'all_online']);

        $response->assertCreated()
            ->assertJsonPath('meta.scope', 'all_online')
            ->assertJsonPath('meta.recipient_count', 2)
            ->assertJsonPath('meta.queued_token_count', 3)
            ->assertJsonPath('meta.skipped_user_count', 5)
            ->assertJsonPath('meta.failed_user_count', 0);

        $recipientIds = collect($response->json('data.recipients'))->pluck('user_id');
        $this->assertEqualsCanonicalizing([$firstEligible->id, $secondEligible->id], $recipientIds->all());
        Queue::assertPushed(SendFcmNotification::class, 3);
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $firstToken->id);
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $secondToken->id);
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $secondDeviceToken->id);
        Queue::assertNotPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $staleSecondToken->id);
        Queue::assertNotPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $adminSecondToken->id);
        $this->assertDatabaseMissing('user_certifications', ['user_id' => $firstEligible->id]);
        $this->assertDatabaseMissing('asset_assignments', ['user_id' => $firstEligible->id]);

        $audit = AuditLog::query()->where('action', 'test_alert.sent')->latest('created_at')->firstOrFail();
        $this->assertSame('all_online', $audit->metadata['scope']);
        $this->assertSame(2, $audit->metadata['recipient_count']);
        $this->assertSame(3, $audit->metadata['queued_device_count']);
        $this->assertSame(5, $audit->metadata['skipped_user_count']);
        $this->assertSame(0, $audit->metadata['failed_user_count']);
        $this->assertSame(2, $audit->metadata['selected_user_count']);

        $this->assertFalse($firstEligible->statuses()->latest('effective_at')->firstOrFail()->is_available);
        $this->assertNotNull($withoutToken->id);
    }

    public function test_all_online_continues_when_one_selected_recipient_cannot_be_persisted(): void
    {
        Queue::fake();
        $actor = $this->user('robust-coordinator@example.test');
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);

        $failing = $this->operator('failing-recipient@example.test');
        $this->token($failing, 'failing-recipient', lastSeenAt: now());
        $successful = $this->operator('successful-recipient@example.test');
        $successfulToken = $this->token($successful, 'successful-recipient', lastSeenAt: now());

        DispatchRecipient::creating(function (DispatchRecipient $recipient) use ($failing): void {
            if ($recipient->user_id === $failing->id) {
                throw new RuntimeException('Simulated recipient persistence failure.');
            }
        });

        try {
            $response = $this->asWebClient($actor)->postJson('/api/test-alert', ['scope' => 'all_online']);
        } finally {
            DispatchRecipient::flushEventListeners();
        }

        $response->assertCreated()
            ->assertJsonPath('meta.recipient_count', 1)
            ->assertJsonPath('meta.queued_token_count', 1)
            ->assertJsonPath('meta.skipped_user_count', 0)
            ->assertJsonPath('meta.failed_user_count', 1)
            ->assertJsonPath('data.recipients.0.user_id', $successful->id);

        $this->assertDatabaseMissing('dispatch_recipients', ['user_id' => $failing->id]);
        $this->assertDatabaseHas('dispatch_recipients', ['user_id' => $successful->id]);
        Queue::assertPushed(SendFcmNotification::class, 1);
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $successfulToken->id);
    }

    public function test_all_online_surfaces_complete_recipient_persistence_failure_as_retryable_server_error(): void
    {
        Queue::fake();
        $actor = $this->user('failed-coordinator@example.test', pushEnabled: true);
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);
        $this->token($actor, 'failed-coordinator', lastSeenAt: now());
        $previous = app(TestAlertService::class)->send($actor)['dispatch'];
        $previousOutbox = DispatchPushOutbox::query()
            ->where('dispatch_request_id', $previous->id)
            ->sole();

        $operator = $this->operator('failed-operator@example.test');
        $this->token($operator, 'failed-operator', lastSeenAt: now());

        DispatchRecipient::creating(static function (): void {
            throw new RuntimeException('Simulated complete recipient persistence failure.');
        });

        try {
            $response = $this->asWebClient($actor)->postJson('/api/test-alert', ['scope' => 'all_online']);
        } finally {
            DispatchRecipient::flushEventListeners();
        }

        $response->assertServerError();
        $this->assertDatabaseCount('dispatch_requests', 1);
        $this->assertSame('sent', $previous->refresh()->status);
        $this->assertSame('active', $previous->incident()->firstOrFail()->status);
        $this->assertNull($previousOutbox->refresh()->cancelled_at);
        $this->assertDatabaseCount('dispatch_recipients', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'test_alert.sent']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'test_alert.not_sent']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'test_alert.superseded']);
        Queue::assertPushed(SendFcmNotification::class, 1);
    }

    public function test_all_online_rejects_an_empty_target_set_atomically(): void
    {
        Queue::fake();
        $actor = $this->user('empty-coordinator@example.test');
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);

        $offline = $this->operator('empty-offline@example.test');
        $this->token($offline, 'empty-offline', lastSeenAt: now()->subMinutes(FcmToken::onlineThresholdMinutes() + 1));

        $response = $this->asWebClient($actor)->postJson('/api/test-alert', ['scope' => 'all_online']);

        $response->assertUnprocessable()
            ->assertJsonPath('error.details.recipients.0', 'Geen online operator-apps gevonden.');
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertDatabaseCount('dispatch_recipients', 0);
        Queue::assertNothingPushed();

        $audit = AuditLog::query()->where('action', 'test_alert.not_sent')->firstOrFail();
        $this->assertSame('all_online', $audit->metadata['scope']);
        $this->assertSame(1, $audit->metadata['skipped_user_count']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'test_alert.sent']);
    }

    public function test_scope_is_validated(): void
    {
        Queue::fake();
        $authorized = $this->user('validation-coordinator@example.test', pushEnabled: true);
        $this->grant($authorized, ['incidents.dispatch.manage'], operator: false, admin: true);
        $this->token($authorized, 'validation-coordinator', lastSeenAt: now());

        $this->asWebClient($authorized)
            ->postJson('/api/test-alert', ['scope' => 'everyone'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.scope.0', 'The selected scope is invalid.');

        $this->assertDatabaseCount('dispatch_requests', 0);
        Queue::assertNothingPushed();
    }

    public function test_dispatch_permission_is_required(): void
    {
        Queue::fake();
        $unauthorized = $this->user('unauthorized@example.test', pushEnabled: true);
        $this->grant($unauthorized, [], operator: false, admin: true);
        $this->token($unauthorized, 'unauthorized', lastSeenAt: now());

        $this->asWebClient($unauthorized)
            ->postJson('/api/test-alert', ['scope' => 'self'])
            ->assertForbidden();

        $this->assertDatabaseCount('dispatch_requests', 0);
        Queue::assertNothingPushed();
    }

    public function test_anonymous_and_pending_two_factor_requests_cannot_send_test_alerts(): void
    {
        Queue::fake();

        $this->postJson('/api/test-alert', ['scope' => 'all_online'])
            ->assertUnauthorized();

        $actor = $this->user('pending-two-factor@example.test');
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);
        $pendingToken = $actor->createToken(
            'Pending test alert client',
            ['2fa:pending', 'client:web'],
            now()->addMinutes(5),
        )->plainTextToken;

        $this->withToken($pendingToken)
            ->postJson('/api/test-alert', ['scope' => 'all_online'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $this->assertDatabaseCount('dispatch_requests', 0);
        Queue::assertNothingPushed();
    }

    private function operator(
        string $email,
        bool $pushEnabled = true,
        string $accountStatus = 'active',
    ): User {
        $user = $this->user($email, $pushEnabled, $accountStatus);
        $this->grant($user, [], operator: true, admin: false);

        return $user;
    }

    private function user(
        string $email,
        bool $pushEnabled = false,
        string $accountStatus = 'active',
    ): User {
        return User::query()->create([
            'name' => 'Test User',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => $accountStatus,
            'push_enabled' => $pushEnabled,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function grant(User $user, array $permissionNames, bool $operator, bool $admin): Role
    {
        $role = Role::query()->create([
            'name' => 'test-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Test role',
            'can_use_operator_app' => $operator,
            'can_use_admin_app' => $admin,
        ]);
        $permissions = collect($permissionNames)->map(fn (string $name): Permission => Permission::query()->firstOrCreate(
            ['name' => $name],
            [
                'category' => 'test',
                'display_name' => $name,
                'description' => 'Test permission',
            ],
        ));
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $role;
    }

    private function token(
        User $user,
        string $deviceId,
        string $clientType = 'operator',
        mixed $lastSeenAt = null,
    ): FcmToken {
        $token = 'token-'.$deviceId;

        return FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'platform' => 'android',
            'client_type' => $clientType,
            'is_active' => true,
            'last_seen_at' => $lastSeenAt,
        ]);
    }

    private function enableSpeech(User $actor): void
    {
        config()->set('dis.speech.cache_hmac_key', str_repeat('test-alert-speech-key-', 3));
        SpeechModelInstallation::query()->create([
            'catalog_key' => 'voxcpm2',
            'revision' => (string) config('dis.speech.models.voxcpm2.revision'),
            'weights_sha256' => (string) config('dis.speech.models.voxcpm2.weights_sha256'),
            'status' => 'installed',
            'progress_percent' => 100,
            'requested_by' => $actor->id,
            'license_confirmed_at' => now(),
            'installed_at' => now(),
        ]);
        foreach ([
            'speech.enabled' => true,
            'speech.model_id' => 'voxcpm2',
            'speech.voice_profile_id' => null,
            'speech.speed' => 1.0,
        ] as $key => $value) {
            $this->setting($key, $value, $actor);
        }
    }

    private function configureWeeklySchedule(
        User $actor,
        int $dayOfWeek = 1,
        string $time = '09:00',
        string $message = 'Duurzame wekelijkse proefalarmering.',
    ): void {
        foreach ([
            'test_alert.schedule_enabled' => true,
            'test_alert.schedule_day_of_week' => $dayOfWeek,
            'test_alert.schedule_time' => $time,
            'test_alert.message' => $message,
        ] as $key => $value) {
            $this->setting($key, $value, $actor);
        }
    }

    private function setting(string $key, mixed $value, User $actor): void
    {
        SystemSetting::query()->updateOrCreate(['key' => $key], [
            'value' => $value,
            'is_sensitive' => str_starts_with($key, 'speech.templates.'),
            'updated_by' => $actor->id,
        ]);
    }

    private function asWebClient(User $user): static
    {
        Auth::forgetGuards();
        $token = $user->createToken('Test alert scope', ['*', 'client:web'], now()->addHour())->plainTextToken;

        return $this->flushHeaders()->withToken($token);
    }
}
