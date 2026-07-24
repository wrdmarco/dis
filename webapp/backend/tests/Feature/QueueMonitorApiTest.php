<?php

namespace Tests\Feature;

use App\Contracts\DispatchNotificationQueue;
use App\Contracts\QueueTransportMetrics;
use App\Jobs\SendFcmNotification;
use App\Models\AuditLog;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\PushQueueWorkItem;
use App\Models\Role;
use App\Models\User;
use App\Services\WebSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class QueueMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_ORIGIN = 'https://dis.example.test';

    public function test_queue_monitor_requires_authentication_and_health_permission(): void
    {
        $this->getJson('/api/admin/queues')->assertUnauthorized();

        $this->asAdminClient($this->user('queue-denied@example.test'))
            ->getJson('/api/admin/queues')
            ->assertForbidden();
    }

    public function test_transport_count_honestly_represents_non_outbox_push_jobs_without_payload_details(): void
    {
        $this->mockTransport(pushPending: 7);
        $viewer = $this->user('queue-push-viewer@example.test', ['system.health.view']);

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push')
            ->assertOk()
            ->assertJsonPath('data.queues.0.key', 'push')
            ->assertJsonPath('data.queues.0.configured_parallelism', 4)
            ->assertJsonPath('data.queues.0.transport_pending_count', 7)
            ->assertJsonCount(0, 'data.items');

        $this->assertStringNotContainsString('payload', (string) $response->getContent());
        $this->assertStringNotContainsString('token', (string) $response->getContent());
    }

    public function test_queue_monitor_lists_safe_non_outbox_push_ledger_items(): void
    {
        $this->mockTransport(pushPending: 1);
        $viewer = $this->user('queue-ledger-viewer@example.test', ['system.health.view']);
        PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', 'opaque-transport-id'),
            'safe_message_type' => 'manual_admin',
            'status' => PushQueueWorkItem::STATUS_RETRYING,
            'attempts' => 2,
            'error_code' => 'queue_retry_scheduled',
            'queued_at' => now()->subSeconds(4),
            'processing_started_at' => now()->subSeconds(3),
            'next_attempt_at' => now()->addSeconds(12),
        ]);

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push')
            ->assertOk()
            ->assertJsonPath('data.summary.retrying', 1)
            ->assertJsonPath('data.items.0.label', 'Handmatige pushmelding')
            ->assertJsonPath('data.items.0.state', 'retrying')
            ->assertJsonPath('data.items.0.attempts', 2);

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('queue_job_id', $content);
        $this->assertStringNotContainsString('opaque-transport-id', $content);
        $this->assertStringNotContainsString('payload', $content);
    }

    public function test_completed_work_never_displaces_open_work_before_pagination(): void
    {
        $this->mockTransport(pushPending: 1);
        $viewer = $this->user('queue-open-pagination@example.test', ['system.health.view']);
        foreach (range(1, 60) as $index) {
            PushQueueWorkItem::query()->create([
                'queue_job_id' => hash('sha256', 'completed-'.$index),
                'safe_message_type' => 'manual_admin',
                'status' => PushQueueWorkItem::STATUS_COMPLETED,
                'attempts' => 1,
                'queued_at' => now()->subMinute(),
                'processing_started_at' => now()->subSeconds(30),
                'finished_at' => now(),
            ]);
        }
        $open = PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', 'still-open'),
            'safe_message_type' => 'manual_admin',
            'status' => PushQueueWorkItem::STATUS_QUEUED,
            'attempts' => 0,
            'queued_at' => now(),
        ]);

        $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push&state=open&per_page=25')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', (string) $open->id)
            ->assertJsonPath('data.items.0.state', 'queued')
            ->assertJsonPath('data.summary.completed', 0);
    }

    public function test_current_outbox_lifecycle_overlay_drives_retry_items_and_counts(): void
    {
        $this->mockTransport(pushPending: 2);
        $viewer = $this->user('queue-outbox-overlay@example.test', ['system.health.view']);
        $normalRetry = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(60),
            'processing_started_at' => now()->subSeconds(55),
            'retry_at' => now()->addSeconds(5),
            'last_error_code' => 'delivery_retry_scheduled',
        ]);
        $this->linkedPushWorkItem($normalRetry, 'normal-retry-older', [
            'status' => PushQueueWorkItem::STATUS_PROCESSING,
            'attempts' => 1,
            'queued_at' => now()->subSeconds(59),
            'processing_started_at' => now()->subSeconds(54),
        ], now()->subSeconds(50));
        $this->linkedPushWorkItem($normalRetry, 'normal-retry-current', [
            'status' => PushQueueWorkItem::STATUS_RETRYING,
            'attempts' => 2,
            'error_code' => 'queue_retry_scheduled',
            'queued_at' => now()->subSeconds(59),
            'processing_started_at' => now()->subSeconds(44),
            'next_attempt_at' => now()->addSeconds(30),
        ], now()->subSeconds(40));

        $timeoutRetry = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(30),
            'processing_started_at' => now()->subSeconds(20),
        ]);
        $this->linkedPushWorkItem($timeoutRetry, 'timeout-retry-current', [
            'status' => PushQueueWorkItem::STATUS_RETRYING,
            'attempts' => 1,
            'error_code' => 'queue_timeout_retry_scheduled',
            'queued_at' => now()->subSeconds(29),
            'processing_started_at' => now()->subSeconds(20),
            'next_attempt_at' => now()->addSeconds(40),
        ], now()->subSeconds(15));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push&state=retrying&per_page=100')
            ->assertOk()
            ->assertJsonPath('data.summary.retrying', 2)
            ->assertJsonPath('data.summary.processing', 0)
            ->assertJsonPath('meta.total', 2);
        $lifecycleQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'push_queue_work_items'));
        DB::disableQueryLog();

        $items = collect($response->json('data.items'))->keyBy('id');
        $this->assertCount(
            4,
            $lifecycleQueries,
            'The fixed count-and-item query set must not grow with the number of outbox rows.',
        );
        $this->assertCount(2, $items);
        $this->assertSame('retrying', $items[(string) $normalRetry->id]['state']);
        $this->assertSame(2, $items[(string) $normalRetry->id]['attempts']);
        $this->assertSame('queue_retry_scheduled', $items[(string) $normalRetry->id]['error_code']);
        $this->assertNotNull($items[(string) $normalRetry->id]['started_at']);
        $this->assertNotNull($items[(string) $normalRetry->id]['next_attempt_at']);
        $this->assertSame('retrying', $items[(string) $timeoutRetry->id]['state']);
        $this->assertSame(1, $items[(string) $timeoutRetry->id]['attempts']);
        $this->assertSame(
            'queue_timeout_retry_scheduled',
            $items[(string) $timeoutRetry->id]['error_code'],
        );
        $this->assertNotNull($items[(string) $timeoutRetry->id]['next_attempt_at']);
    }

    public function test_terminal_between_cycle_and_new_claim_outboxes_ignore_ineligible_lifecycle_rows(): void
    {
        $this->mockTransport(pushPending: 1);
        $viewer = $this->user('queue-outbox-precedence@example.test', ['system.health.view']);

        $exhausted = $this->pushOutbox($viewer, [
            'queued_at' => null,
            'processing_started_at' => null,
            'retry_at' => now()->addMinute(),
            'attempts' => 1,
            'last_error_code' => 'delivery_retry_exhausted',
        ]);
        $this->linkedPushWorkItem($exhausted, 'exhausted-old-cycle', [
            'status' => PushQueueWorkItem::STATUS_FAILED,
            'attempts' => 4,
            'error_code' => 'queue_job_failed',
            'finished_at' => now()->subSecond(),
        ], now()->subSeconds(2));

        $cancelled = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(20),
            'cancelled_at' => now()->subSeconds(2),
            'attempts' => 0,
            'last_error_code' => 'stale_dispatch_phase',
        ]);
        $this->linkedPushWorkItem($cancelled, 'cancelled-late-ledger', [
            'status' => PushQueueWorkItem::STATUS_RETRYING,
            'attempts' => 3,
            'error_code' => 'queue_retry_scheduled',
            'next_attempt_at' => now()->addSeconds(30),
        ], now()->subSecond());

        $delivered = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(20),
            'delivered_at' => now()->subSecond(),
            'attempts' => 0,
        ]);
        $this->linkedPushWorkItem($delivered, 'delivered-late-ledger', [
            'status' => PushQueueWorkItem::STATUS_FAILED,
            'attempts' => 4,
            'error_code' => 'queue_job_failed',
            'finished_at' => now(),
        ], now());

        $newClaim = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(5),
            'processing_started_at' => now()->subSeconds(4),
            'attempts' => 2,
            'last_error_code' => 'outbox_processing',
        ]);
        $this->linkedPushWorkItem($newClaim, 'historical-ledger', [
            'status' => PushQueueWorkItem::STATUS_FAILED,
            'attempts' => 4,
            'error_code' => 'queue_job_failed',
            'finished_at' => now()->subSeconds(8),
        ], now()->subSeconds(10));

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push&per_page=100')
            ->assertOk()
            ->assertJsonPath('data.summary.retrying', 1)
            ->assertJsonPath('data.summary.cancelled', 0)
            ->assertJsonPath('data.summary.completed', 0)
            ->assertJsonPath('data.summary.processing', 1)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('meta.total', 2);

        $items = collect($response->json('data.items'))->keyBy('id');
        $this->assertCount(2, $items);
        $this->assertSame('retrying', $items[(string) $exhausted->id]['state']);
        $this->assertSame(1, $items[(string) $exhausted->id]['attempts']);
        $this->assertSame(['start'], $items[(string) $exhausted->id]['available_actions']);
        $this->assertSame(
            'delivery_retry_exhausted',
            $items[(string) $exhausted->id]['error_code'],
        );
        $this->assertArrayNotHasKey((string) $cancelled->id, $items);
        $this->assertArrayNotHasKey((string) $delivered->id, $items);
        $this->assertSame('processing', $items[(string) $newClaim->id]['state']);
        $this->assertSame(2, $items[(string) $newClaim->id]['attempts']);
        $this->assertSame([], $items[(string) $newClaim->id]['available_actions']);
        $this->assertSame('outbox_processing', $items[(string) $newClaim->id]['error_code']);
    }

    public function test_queue_actions_require_separate_management_permission(): void
    {
        Queue::fake();
        $viewer = $this->user('queue-action-viewer@example.test', ['system.health.view']);
        $outbox = $this->pushOutbox($viewer, [
            'message_type' => 'dispatch_update',
            'available_at' => now()->addHour(),
        ]);

        $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push&state=open')
            ->assertOk()
            ->assertJsonPath('data.items.0.available_actions.0', 'start');

        $this->asAdminClient($viewer)
            ->postJson('/api/admin/queues/push/'.$outbox->id.'/start')
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertNull($outbox->fresh()?->queued_at);
    }

    public function test_queue_actions_reject_personal_access_tokens_even_for_a_manager(): void
    {
        Queue::fake();
        $manager = $this->user('queue-token-manager@example.test', [
            'system.health.view',
            'system.queues.manage',
        ]);
        $manager->roles()->firstOrFail()->update(['can_use_operator_app' => true]);
        $outbox = $this->pushOutbox($manager, [
            'message_type' => 'dispatch_update',
            'available_at' => now()->addHour(),
        ]);

        foreach (['client:operator', 'client:web'] as $ability) {
            $token = $manager->createToken(
                'Queue action bearer test',
                ['*', $ability],
                now()->addHour(),
            )->plainTextToken;
            Auth::forgetGuards();

            $this->withToken($token)
                ->postJson('/api/admin/queues/push/'.$outbox->id.'/start')
                ->assertForbidden()
                ->assertJsonPath('error.code', 'stateful_web_session_required');
        }

        Queue::assertNothingPushed();
        $this->assertNull($outbox->fresh()?->queued_at);
    }

    public function test_manager_can_start_an_unclaimed_outbox_only_once_and_action_is_audited(): void
    {
        Queue::fake();
        $manager = $this->user('queue-start-manager@example.test', [
            'system.health.view',
            'system.queues.manage',
        ]);
        $outbox = $this->pushOutbox($manager, [
            'message_type' => 'dispatch_update',
            'available_at' => now()->addHour(),
        ]);

        $this->asWebSession($manager)
            ->postJson('/api/admin/queues/push/'.$outbox->id.'/start')
            ->assertStatus(202)
            ->assertJsonPath('data.action', 'started')
            ->assertJsonMissingPath('data.item');

        $this->asWebSession($manager)
            ->postJson('/api/admin/queues/push/'.$outbox->id.'/start')
            ->assertConflict()
            ->assertJsonPath('error.code', 'queue_action_conflict');

        Queue::assertPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->dispatchPushOutboxId === (string) $outbox->id,
        );
        Queue::assertPushed(SendFcmNotification::class, 1);
        $this->assertNotNull($outbox->fresh()?->queued_at);

        $audit = AuditLog::query()
            ->where('action', 'system.queue.started')
            ->where('target_id', $outbox->id)
            ->firstOrFail();
        $requestAudit = AuditLog::query()
            ->where('action', 'system.queue.action_requested')
            ->where('target_id', $outbox->id)
            ->firstOrFail();
        $this->assertSame((string) $manager->id, (string) $audit->actor_id);
        $this->assertSame('pending', $audit->metadata['previous_state'] ?? null);
        $this->assertSame('started', $audit->metadata['outcome'] ?? null);
        $this->assertSame((string) $manager->id, (string) $requestAudit->actor_id);
        $this->assertSame('pending', $requestAudit->metadata['previous_state'] ?? null);
        $this->assertSame('start', $requestAudit->metadata['requested_action'] ?? null);
        $metadata = json_encode([
            $requestAudit->metadata,
            $audit->metadata,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('token', $metadata);
        $this->assertStringNotContainsString('body', $metadata);
        $this->assertStringNotContainsString('payload', $metadata);
    }

    public function test_manager_can_retry_only_a_failed_current_outbox_cycle(): void
    {
        $manager = $this->user('queue-retry-manager@example.test', [
            'system.health.view',
            'system.queues.manage',
        ]);
        $failedOutbox = $this->pushOutbox($manager, [
            'message_type' => 'dispatch_update',
            'queued_at' => now()->subSeconds(30),
            'processing_started_at' => now()->subSeconds(20),
            'last_error_code' => 'queue_job_failed',
        ]);
        $this->linkedPushWorkItem($failedOutbox, 'failed-current-cycle', [
            'status' => PushQueueWorkItem::STATUS_FAILED,
            'attempts' => 4,
            'error_code' => 'queue_job_failed',
            'finished_at' => now()->subSecond(),
        ], now()->subSecond());

        $standaloneJobId = 'failed-without-durable-outbox';
        $standaloneJobUuid = (string) Str::uuid();
        $standalonePayload = json_encode([
            'uuid' => $standaloneJobUuid,
            'id' => $standaloneJobId,
            'displayName' => SendFcmNotification::class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'attempts' => 4,
            'data' => [
                'commandName' => SendFcmNotification::class,
                'command' => 'ENCRYPTED-STANDALONE-PAYLOAD',
            ],
        ], JSON_THROW_ON_ERROR);
        $unsafeLedger = PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', $standaloneJobId),
            'queue_job_uuid' => $standaloneJobUuid,
            'safe_message_type' => 'manual_admin',
            'status' => PushQueueWorkItem::STATUS_FAILED,
            'attempts' => 4,
            'error_code' => 'queue_job_failed',
            'finished_at' => now(),
        ]);
        DB::table('failed_jobs')->insert([
            'id' => (string) Str::uuid(),
            'uuid' => $standaloneJobUuid,
            'connection' => 'push',
            'queue' => 'push',
            'payload' => $standalonePayload,
            'exception' => 'sanitized test exception',
            'failed_at' => now(),
        ]);

        $recordingQueue = new class implements DispatchNotificationQueue
        {
            public int $enqueueCount = 0;

            public bool $requestAuditSeenBeforeEnqueue = false;

            public function enqueue(DispatchPushOutbox $notification): void
            {
                $this->enqueueCount++;
                $this->requestAuditSeenBeforeEnqueue = AuditLog::query()
                    ->where('action', 'system.queue.action_requested')
                    ->where('target_type', DispatchPushOutbox::class)
                    ->where('target_id', $notification->id)
                    ->exists();
            }
        };
        $this->app->instance(DispatchNotificationQueue::class, $recordingQueue);

        $items = collect($this->asAdminClient($manager)
            ->getJson('/api/admin/queues?queue=push&state=open&per_page=100')
            ->assertOk()
            ->json('data.items'))
            ->keyBy('id');
        $this->assertSame(['retry'], $items[(string) $failedOutbox->id]['available_actions']);
        $this->assertSame([], $items[(string) $unsafeLedger->id]['available_actions']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/queues/push/'.$failedOutbox->id.'/retry')
            ->assertStatus(202)
            ->assertJsonPath('data.action', 'retried')
            ->assertJsonMissingPath('data.item');

        $this->asWebSession($manager)
            ->postJson('/api/admin/queues/push/'.$unsafeLedger->id.'/retry')
            ->assertConflict()
            ->assertJsonPath('error.code', 'queue_action_conflict');

        $this->assertSame(1, $recordingQueue->enqueueCount);
        $this->assertTrue($recordingQueue->requestAuditSeenBeforeEnqueue);
        $this->assertDatabaseHas('failed_jobs', [
            'uuid' => $standaloneJobUuid,
            'payload' => $standalonePayload,
        ]);
        $this->assertSame(
            PushQueueWorkItem::STATUS_FAILED,
            $unsafeLedger->refresh()->status,
        );
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'system.queue.action_requested',
            'target_type' => PushQueueWorkItem::class,
            'target_id' => $unsafeLedger->id,
        ]);

        $requestAudit = AuditLog::query()
            ->where('action', 'system.queue.action_requested')
            ->where('target_type', DispatchPushOutbox::class)
            ->where('target_id', $failedOutbox->id)
            ->sole();
        $outcomeAudit = AuditLog::query()
            ->where('action', 'system.queue.retried')
            ->where('target_type', DispatchPushOutbox::class)
            ->where('target_id', $failedOutbox->id)
            ->sole();
        $this->assertSame((string) $manager->id, (string) $requestAudit->actor_id);
        $this->assertSame('failed', $requestAudit->metadata['previous_state'] ?? null);
        $this->assertSame('retry', $requestAudit->metadata['requested_action'] ?? null);
        $this->assertSame((string) $manager->id, (string) $outcomeAudit->actor_id);
        $this->assertSame('retried', $outcomeAudit->metadata['outcome'] ?? null);

        $encoded = json_encode([
            $items[(string) $unsafeLedger->id],
            $requestAudit->metadata,
            $outcomeAudit->metadata,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($standaloneJobUuid, $encoded);
        $this->assertStringNotContainsString($standaloneJobId, $encoded);
        $this->assertStringNotContainsString(hash('sha256', $standaloneJobId), $encoded);
        $this->assertStringNotContainsString('ENCRYPTED-STANDALONE-PAYLOAD', $encoded);
    }

    public function test_queue_monitor_rejects_unbounded_pagination_and_invalid_filters(): void
    {
        $viewer = $this->user('queue-validation@example.test', ['system.health.view']);

        $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?per_page=101&page=2001&queue=speech&state=reserved')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure([
                'error' => [
                    'details' => ['per_page', 'page', 'queue', 'state'],
                ],
            ]);
    }

    public function test_push_only_poll_queries_only_the_push_domain(): void
    {
        $this->mockTransport(pushPending: 1);
        $viewer = $this->user('queue-query-scope@example.test', ['system.health.view']);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push')
            ->assertOk();

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        $this->assertStringNotContainsString('speech_', strtolower($sql));
        DB::disableQueryLog();
    }

    private function mockTransport(int $pushPending = 2): void
    {
        $transport = Mockery::mock(QueueTransportMetrics::class);
        $transport->shouldReceive('pendingCount')->with('push', 'push')->zeroOrMoreTimes()->andReturn($pushPending);
        $transport->shouldReceive('failedCount')->with('push')->zeroOrMoreTimes()->andReturn(0);
        $this->app->instance(QueueTransportMetrics::class, $transport);
    }

    /** @param array<string, mixed> $attributes */
    private function pushOutbox(User $user, array $attributes = []): DispatchPushOutbox
    {
        $suffix = strtolower((string) str()->ulid());
        $incident = Incident::query()->create([
            'reference' => 'QUEUE-'.$suffix,
            'title' => 'Queue monitor fixture',
            'priority' => 'normal',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $user->id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Queue monitor fixture',
        ]);
        $providerToken = 'queue-provider-'.$suffix;
        $token = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'queue-device-'.$suffix,
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
        ]);

        return DispatchPushOutbox::query()->create([
            'deduplication_key' => hash('sha256', 'queue-outbox-'.$suffix),
            'dispatch_request_id' => $dispatch->id,
            'fcm_token_id' => $token->id,
            'message_type' => 'dispatch_request',
            'title' => 'Queue monitor fixture',
            'body' => 'Queue monitor fixture',
            'data' => [],
            'available_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function linkedPushWorkItem(
        DispatchPushOutbox $outbox,
        string $jobId,
        array $attributes,
        \DateTimeInterface $createdAt,
    ): PushQueueWorkItem {
        $item = PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', $jobId),
            'safe_message_type' => 'dispatch_request',
            'dispatch_push_outbox_id' => $outbox->id,
            ...$attributes,
        ]);
        $item->timestamps = false;
        $item->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $item;
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'Queue Monitor Test',
            'first_name' => 'Queue',
            'last_name' => 'Monitor Test',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'queue-monitor-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Queue monitor test role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'Queue monitor test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Queue monitor web test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function asWebSession(User $user): static
    {
        config([
            'app.url' => self::WEB_ORIGIN,
            'session.trusted_origins' => [self::WEB_ORIGIN],
            'sanctum.stateful' => ['dis.example.test'],
        ]);

        Auth::forgetGuards();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        $timestamp = now()->getTimestamp();
        $csrfToken = hash('sha256', 'queue-monitor-browser-session-'.$user->id);

        return $this->actingAs($user, 'web')
            ->withSession([
                '_token' => $csrfToken,
                WebSessionService::KEY_AUTHENTICATED_AT => $timestamp,
                WebSessionService::KEY_LAST_ACTIVITY_AT => $timestamp,
                WebSessionService::KEY_AUTH_VERSION => (int) $user->auth_session_version,
            ])
            ->withHeaders([
                'Accept' => 'application/json',
                'Origin' => self::WEB_ORIGIN,
                'Referer' => self::WEB_ORIGIN.'/',
                'Sec-Fetch-Site' => 'same-origin',
                'X-CSRF-TOKEN' => $csrfToken,
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->withServerVariables([
                'HTTP_HOST' => 'dis.example.test',
                'SERVER_NAME' => 'dis.example.test',
                'SERVER_PORT' => 443,
                'HTTPS' => 'on',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REMOTE_ADDR' => '192.0.2.70',
            ]);
    }
}
