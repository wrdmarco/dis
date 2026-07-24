<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\PushQueueWorkItem;
use App\Services\PushQueueLifecyclePolicy;
use App\Services\PushQueueLifecycleTracker;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class PushQueueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_is_monotonic_when_worker_processing_precedes_queued_listener(): void
    {
        $tracker = app(PushQueueLifecycleTracker::class);
        $jobId = 'fast-worker-job-id';

        $tracker->processing(new JobProcessing('push', $this->runtimeJob($jobId, 1)));
        $this->assertDatabaseHas('push_queue_work_items', [
            'queue_job_id' => hash('sha256', $jobId),
            'safe_message_type' => 'push_notification',
            'status' => PushQueueWorkItem::STATUS_PROCESSING,
            'attempts' => 1,
        ]);

        $firstAttemptStartedAt = PushQueueWorkItem::query()->sole()->processing_started_at;
        $this->assertNotNull($firstAttemptStartedAt);
        $this->travel(5)->seconds();
        $tracker->queued($this->queuedEvent(
            $jobId,
            'manual_admin',
            'ZEER GEHEIME TITEL',
            'ZEER GEHEIME MELDTEKST',
            ['incident_id' => 'ZEER-GEHEIM-INCIDENT'],
        ));
        $item = PushQueueWorkItem::query()->sole();
        $this->assertSame(PushQueueWorkItem::STATUS_PROCESSING, $item->status);
        $this->assertSame('manual_admin', $item->safe_message_type);
        $this->assertNotNull($item->queued_at);
        $this->assertTrue($item->queued_at->lessThanOrEqualTo($firstAttemptStartedAt));

        $tracker->retrying(new JobReleasedAfterException('push', $this->runtimeJob($jobId, 1), 15));
        $this->assertSame(PushQueueWorkItem::STATUS_RETRYING, $item->refresh()->status);
        $this->assertNotNull($item->next_attempt_at);

        $this->travel(5)->seconds();
        $tracker->processing(new JobProcessing('push', $this->runtimeJob($jobId, 2)));
        $this->assertSame(PushQueueWorkItem::STATUS_PROCESSING, $item->refresh()->status);
        $this->assertSame(2, $item->attempts);
        $this->assertTrue($item->processing_started_at->greaterThan($firstAttemptStartedAt));

        $tracker->processed(new JobProcessed('push', $this->runtimeJob($jobId, 2)));
        $this->assertSame(PushQueueWorkItem::STATUS_COMPLETED, $item->refresh()->status);
        $this->assertNotNull($item->finished_at);

        $tracker->queued($this->queuedEvent(
            $jobId,
            'manual_admin',
            'GEHEIM',
            'GEHEIM',
            ['token' => 'GEHEIM'],
        ));
        $this->assertSame(PushQueueWorkItem::STATUS_COMPLETED, $item->refresh()->status);
    }

    public function test_late_lower_attempt_events_cannot_regress_newer_attempt_and_terminal_is_final(): void
    {
        $tracker = app(PushQueueLifecycleTracker::class);
        $jobId = 'attempt-order-job-id';
        $tracker->processing(new JobProcessing('push', $this->runtimeJob($jobId, 2)));
        $tracker->retrying(new JobReleasedAfterException('push', $this->runtimeJob($jobId, 2), 30));

        $item = PushQueueWorkItem::query()->sole();
        $attributes = [
            'status',
            'attempts',
            'queued_at',
            'processing_started_at',
            'next_attempt_at',
            'finished_at',
            'error_code',
            'updated_at',
        ];
        $newerAttempt = $item->only($attributes);

        $this->travel(5)->seconds();
        $tracker->processing(new JobProcessing('push', $this->runtimeJob($jobId, 1)));
        $this->assertEquals($newerAttempt, $item->refresh()->only($attributes));

        $tracker->retrying(new JobReleasedAfterException('push', $this->runtimeJob($jobId, 1), 5));
        $this->assertEquals($newerAttempt, $item->refresh()->only($attributes));

        $tracker->processed(new JobProcessed('push', $this->runtimeJob($jobId, 1)));
        $this->assertEquals($newerAttempt, $item->refresh()->only($attributes));

        $tracker->failed(new JobFailed(
            'push',
            $this->runtimeJob($jobId, 1),
            new RuntimeException('late lower attempt'),
        ));
        $this->assertEquals($newerAttempt, $item->refresh()->only($attributes));

        $tracker->processing(new JobProcessing('push', $this->runtimeJob($jobId, 3)));
        $this->assertSame(PushQueueWorkItem::STATUS_PROCESSING, $item->refresh()->status);
        $this->assertSame(3, $item->attempts);
        $tracker->processed(new JobProcessed('push', $this->runtimeJob($jobId, 3)));
        $terminal = $item->refresh()->only($attributes);
        $this->assertSame(PushQueueWorkItem::STATUS_COMPLETED, $item->status);

        $this->travel(5)->seconds();
        $tracker->processing(new JobProcessing('push', $this->runtimeJob($jobId, 4)));
        $tracker->retrying(new JobReleasedAfterException('push', $this->runtimeJob($jobId, 4), 5));
        $tracker->failed(new JobFailed(
            'push',
            $this->runtimeJob($jobId, 4),
            new RuntimeException('late event after terminal state'),
        ));
        $this->assertEquals($terminal, $item->refresh()->only($attributes));
    }

    public function test_first_timeout_is_retrying_until_redis_reservation_becomes_visible(): void
    {
        config()->set('queue.connections.push.retry_after', 240);
        config()->set('dis.queue_monitor.queues.push.worker_timeout_seconds', 180);
        $tracker = app(PushQueueLifecycleTracker::class);
        $jobId = 'first-timeout-job-id';
        $before = now();

        $tracker->timedOut(new JobTimedOut(
            'push',
            $this->runtimeJob($jobId, 1, maxTries: 4),
        ));

        $item = PushQueueWorkItem::query()->sole();
        $this->assertSame(PushQueueWorkItem::STATUS_RETRYING, $item->status);
        $this->assertSame('queue_timeout_retry_scheduled', $item->error_code);
        $this->assertSame(1, $item->attempts);
        $this->assertNotNull($item->next_attempt_at);
        $this->assertGreaterThanOrEqual(
            59,
            $before->diffInSeconds($item->next_attempt_at),
        );
        $this->assertLessThanOrEqual(
            61,
            $before->diffInSeconds($item->next_attempt_at),
        );
    }

    public function test_exhausted_timeout_is_terminal_and_queued_enrichment_never_exposes_payload_data(): void
    {
        $tracker = app(PushQueueLifecycleTracker::class);
        $jobId = 'timeout-job-id';
        $tracker->timedOut(new JobTimedOut(
            'push',
            $this->runtimeJob($jobId, 4, maxTries: 4, hasFailed: true),
        ));
        $finishedAt = PushQueueWorkItem::query()->sole()->finished_at;
        $this->assertNotNull($finishedAt);
        $this->travel(5)->seconds();
        $tracker->queued($this->queuedEvent(
            $jobId,
            'location_share_request',
            'PERSOONLIJKE TITEL',
            'PERSOONLIJKE MELDTEKST',
            ['user_id' => 'PERSOONLIJKE-GEBRUIKER'],
        ));
        $tracker->processed(new JobProcessed('push', $this->runtimeJob($jobId, 4)));

        $item = PushQueueWorkItem::query()->sole();
        $this->assertSame(PushQueueWorkItem::STATUS_FAILED, $item->status);
        $this->assertSame('queue_job_timeout_exhausted', $item->error_code);
        $this->assertSame('location_share_request', $item->safe_message_type);
        $this->assertNotNull($item->queued_at);
        $this->assertTrue($item->queued_at->lessThanOrEqualTo($finishedAt));
        $serialized = $item->toJson();
        $this->assertStringNotContainsString('PERSOONLIJKE', $serialized);
        $this->assertStringNotContainsString('user_id', $serialized);
        $this->assertStringNotContainsString('title', $serialized);
        $this->assertStringNotContainsString('body', $serialized);
        $this->assertStringNotContainsString('data', $serialized);
        $this->assertStringNotContainsString($jobId, $serialized);
    }

    public function test_timeout_is_terminal_when_max_tries_are_exhausted_before_failed_flag_is_observed(): void
    {
        app(PushQueueLifecycleTracker::class)->timedOut(new JobTimedOut(
            'push',
            $this->runtimeJob('max-tries-timeout', 4, maxTries: 4, hasFailed: false),
        ));

        $this->assertDatabaseHas('push_queue_work_items', [
            'queue_job_id' => hash('sha256', 'max-tries-timeout'),
            'status' => PushQueueWorkItem::STATUS_FAILED,
            'attempts' => 4,
            'error_code' => 'queue_job_timeout_exhausted',
        ]);
    }

    public function test_real_failed_then_timed_out_event_order_preserves_timeout_error_code(): void
    {
        $tracker = app(PushQueueLifecycleTracker::class);
        $jobId = 'ordered-timeout-events';
        $job = $this->runtimeJob($jobId, 4, maxTries: 4, hasFailed: true);
        $tracker->processing(new JobProcessing('push', $job));

        $tracker->failed(new JobFailed(
            'push',
            $job,
            TimeoutExceededException::forJob($job),
        ));
        $failedAt = PushQueueWorkItem::query()->sole()->finished_at;
        $tracker->timedOut(new JobTimedOut('push', $job));

        $item = PushQueueWorkItem::query()->sole();
        $this->assertSame(PushQueueWorkItem::STATUS_FAILED, $item->status);
        $this->assertSame(4, $item->attempts);
        $this->assertSame('queue_job_timeout_exhausted', $item->error_code);
        $this->assertEquals($failedAt, $item->finished_at);
    }

    public function test_listener_failure_does_not_escape_the_queue_event(): void
    {
        $this->app->bind(
            PushQueueLifecycleTracker::class,
            fn (): never => throw new RuntimeException('monitoring database unavailable'),
        );

        Event::dispatch($this->queuedEvent(
            'best-effort-job',
            'manual_admin',
            'Titel',
            'Tekst',
            [],
        ));

        $this->addToAssertionCount(1);
    }

    public function test_pruning_removes_only_expired_terminal_ledger_rows(): void
    {
        config()->set('dis.retention.push_queue_work_items_days', 7);
        $expiredCompleted = $this->ledgerItem('expired-completed', PushQueueWorkItem::STATUS_COMPLETED, 8);
        $expiredFailed = $this->ledgerItem('expired-failed', PushQueueWorkItem::STATUS_FAILED, 8);
        $active = $this->ledgerItem('old-active', PushQueueWorkItem::STATUS_PROCESSING, 8);
        $recent = $this->ledgerItem('recent-completed', PushQueueWorkItem::STATUS_COMPLETED, 2);

        $this->artisan('dis:prune-operational-data')->assertSuccessful();

        $this->assertDatabaseMissing('push_queue_work_items', ['id' => $expiredCompleted->id]);
        $this->assertDatabaseMissing('push_queue_work_items', ['id' => $expiredFailed->id]);
        $this->assertDatabaseHas('push_queue_work_items', ['id' => $active->id]);
        $this->assertDatabaseHas('push_queue_work_items', ['id' => $recent->id]);
    }

    public function test_stale_active_reconciliation_is_bounded_and_later_worker_activity_recovers(): void
    {
        config()->set('queue.connections.push.retry_after', 240);
        config()->set('dis.queue_monitor.queues.push.worker_timeout_seconds', 180);
        config()->set('dis.queue_monitor.queues.push.max_attempts', 4);
        config()->set('dis.queue_monitor.queues.push.stale_active_after_seconds', 7200);
        $policy = app(PushQueueLifecyclePolicy::class);
        $this->assertSame(900, $policy->maximumLifecycleSeconds());
        $this->assertSame(7200, $policy->staleActiveAfterSeconds());

        $staleQueued = $this->ledgerItem('stale-queued', PushQueueWorkItem::STATUS_QUEUED, 0);
        $stale = $this->ledgerItem('stale-active', PushQueueWorkItem::STATUS_PROCESSING, 0);
        $staleRetrying = $this->ledgerItem('stale-retrying', PushQueueWorkItem::STATUS_RETRYING, 0);
        $stillBlocked = $this->ledgerItem('still-safely-blocked', PushQueueWorkItem::STATUS_QUEUED, 0);
        foreach ([$staleQueued, $stale, $staleRetrying] as $staleItem) {
            $staleItem->timestamps = false;
            $staleItem->forceFill(['updated_at' => now()->subSeconds(7201)])->save();
        }
        $stillBlocked->timestamps = false;
        $stillBlocked->forceFill(['updated_at' => now()->subSeconds(7199)])->save();

        $this->artisan('dis:reconcile-push-queue-work-items')
            ->expectsOutputToContain('"stale_after_seconds":7200')
            ->assertSuccessful();

        foreach ([
            $staleQueued->id => PushQueueWorkItem::STATUS_QUEUED,
            $stale->id => PushQueueWorkItem::STATUS_PROCESSING,
            $staleRetrying->id => PushQueueWorkItem::STATUS_RETRYING,
        ] as $id => $expectedStatus) {
            $this->assertDatabaseHas('push_queue_work_items', [
                'id' => $id,
                'status' => $expectedStatus,
                'error_code' => 'queue_lifecycle_stale',
                'finished_at' => null,
            ]);
        }
        $this->assertDatabaseHas('push_queue_work_items', [
            'id' => $stillBlocked->id,
            'status' => PushQueueWorkItem::STATUS_QUEUED,
        ]);

        app(PushQueueLifecycleTracker::class)->processing(new JobProcessing(
            'push',
            $this->runtimeJob('stale-active', 2),
        ));
        $stale->refresh();
        $this->assertSame(PushQueueWorkItem::STATUS_PROCESSING, $stale->status);
        $this->assertNull($stale->error_code);
        $this->assertNull($stale->finished_at);
    }

    public function test_stale_threshold_cannot_be_configured_below_four_maximum_lifecycles(): void
    {
        config()->set('queue.connections.push.retry_after', 240);
        config()->set('dis.queue_monitor.queues.push.worker_timeout_seconds', 180);
        config()->set('dis.queue_monitor.queues.push.max_attempts', 4);
        config()->set('dis.queue_monitor.queues.push.stale_active_after_seconds', 1);

        $policy = app(PushQueueLifecyclePolicy::class);
        $this->assertSame(900, $policy->maximumLifecycleSeconds());
        $this->assertSame(3600, $policy->staleActiveAfterSeconds());
    }

    public function test_stale_reconciler_uses_the_cluster_safe_minute_schedule(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            "/Schedule::command\\('dis:reconcile-push-queue-work-items'\\)"
            .'.*?->everyMinute\\(\\).*?->onOneServer\\(\\).*?->withoutOverlapping\\(2\\);/s',
            $source,
        );
    }

    private function queuedEvent(
        string $jobId,
        string $messageType,
        string $title,
        string $body,
        array $data,
    ): JobQueued {
        return new JobQueued(
            'push',
            'push',
            $jobId,
            new SendFcmNotification(
                'DATABASE-TOKEN-ID',
                $messageType,
                $title,
                $body,
                $data,
            ),
            'DIT IS EEN GEHEIME RAW QUEUE PAYLOAD',
            null,
        );
    }

    private function runtimeJob(
        string $jobId,
        int $attempts,
        ?int $maxTries = 4,
        bool $hasFailed = false,
        ?int $timeout = null,
        ?int $retryUntil = null,
    ): Job {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getQueue')->andReturn('push');
        $job->shouldReceive('resolveName')->andReturn(SendFcmNotification::class);
        $job->shouldReceive('getJobId')->andReturn($jobId);
        $job->shouldReceive('attempts')->andReturn($attempts);
        $job->shouldReceive('maxTries')->andReturn($maxTries);
        $job->shouldReceive('hasFailed')->andReturn($hasFailed);
        $job->shouldReceive('timeout')->andReturn($timeout);
        $job->shouldReceive('retryUntil')->andReturn($retryUntil);

        return $job;
    }

    private function ledgerItem(string $jobId, string $status, int $ageDays): PushQueueWorkItem
    {
        $item = PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', $jobId),
            'safe_message_type' => 'push_notification',
            'status' => $status,
            'finished_at' => in_array($status, [
                PushQueueWorkItem::STATUS_COMPLETED,
                PushQueueWorkItem::STATUS_FAILED,
            ], true) ? now()->subDays($ageDays) : null,
        ]);
        $item->timestamps = false;
        $item->forceFill([
            'created_at' => now()->subDays($ageDays),
            'updated_at' => now()->subDays($ageDays),
        ])->save();

        return $item;
    }
}
