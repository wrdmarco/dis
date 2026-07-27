<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\Deployment;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\PushQueueWorkItem;
use App\Models\User;
use App\Repositories\PushQueueWorkItemRepository;
use App\Repositories\QueueMonitorRepository;
use App\Services\PushQueueLifecycleTracker;
use App\Services\PushQueueManualActionPolicy;
use App\Services\PushQueueWorkItemActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class PushQueueWorkItemActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_job_is_atomically_moved_to_the_front_without_copying_it(): void
    {
        $jobId = 'ready-job-id';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 0);
        $item = $this->workItem($jobId, $uuid, PushQueueWorkItem::STATUS_QUEUED);
        [$service, $connection] = $this->service(
            ready: [$payload],
            delayed: [],
            reserved: [],
        );
        $connection->shouldReceive('eval')
            ->once()
            ->withArgs(function (
                string $script,
                int $keyCount,
                string $queue,
                string $delayed,
                string $notify,
                string $rawPayload,
            ) use ($payload): bool {
                self::assertStringContainsString("redis.call('lrem'", $script);
                self::assertStringContainsString("redis.call('lpush'", $script);
                self::assertSame(3, $keyCount);
                self::assertSame('queues:push', $queue);
                self::assertSame('queues:push:delayed', $delayed);
                self::assertSame('queues:push:notify', $notify);
                self::assertSame($payload, $rawPayload);

                return true;
            })
            ->andReturn(1);

        self::assertSame('queued', $service->execute((string) $item->id, 'start'));
        self::assertSame(PushQueueWorkItem::STATUS_QUEUED, $item->refresh()->status);
        self::assertNull($item->next_attempt_at);
        self::assertStringNotContainsString($payload, $item->toJson());
    }

    public function test_delayed_job_is_atomically_moved_to_ready_and_wakes_a_worker(): void
    {
        $jobId = 'delayed-job-id';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 2);
        $item = $this->workItem($jobId, $uuid, PushQueueWorkItem::STATUS_RETRYING);
        [$service, $connection] = $this->service(
            ready: [],
            delayed: [$payload],
            reserved: [],
        );
        $connection->shouldReceive('eval')
            ->once()
            ->withArgs(fn (
                string $script,
                int $keyCount,
                string $queue,
                string $delayed,
                string $notify,
                string $rawPayload,
            ): bool => $keyCount === 3
                && $queue === 'queues:push'
                && $delayed === 'queues:push:delayed'
                && $notify === 'queues:push:notify'
                && $rawPayload === $payload
                && str_contains($script, "redis.call('zrem'")
                && str_contains($script, "redis.call('rpush', KEYS[3], 1)"))
            ->andReturn(1);

        self::assertSame('queued', $service->execute((string) $item->id, 'start'));
        $item->refresh();
        self::assertSame(PushQueueWorkItem::STATUS_QUEUED, $item->status);
        self::assertNull($item->next_attempt_at);
        self::assertNull($item->error_code);
    }

    public function test_reserved_job_cannot_be_started_again_or_duplicated(): void
    {
        $jobId = 'reserved-job-id';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 1);
        $item = $this->workItem($jobId, $uuid, PushQueueWorkItem::STATUS_QUEUED);
        [$service, $connection] = $this->service(
            ready: [],
            delayed: [],
            reserved: [$payload],
        );
        $connection->shouldNotReceive('eval');

        self::assertSame('conflict', $service->execute((string) $item->id, 'start'));
        self::assertSame(PushQueueWorkItem::STATUS_QUEUED, $item->refresh()->status);
    }

    public function test_invalid_redis_queue_state_is_reported_as_transport_failure(): void
    {
        $jobId = 'invalid-redis-state-job-id';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 0);
        $item = $this->workItem($jobId, $uuid, PushQueueWorkItem::STATUS_QUEUED);
        [$service, $connection] = $this->service(
            ready: [$payload],
            delayed: [],
            reserved: [],
        );
        $connection->shouldReceive('eval')->once()->andReturn(-2);

        self::assertSame('failed', $service->execute((string) $item->id, 'start'));
        self::assertSame(PushQueueWorkItem::STATUS_QUEUED, $item->refresh()->status);
    }

    public function test_linked_outbox_ready_job_is_prioritized_without_a_second_outbox_claim(): void
    {
        $jobId = 'linked-ready-job-id';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 0);
        [$outbox, $item] = $this->linkedOutbox(
            $jobId,
            $uuid,
            PushQueueWorkItem::STATUS_QUEUED,
        );
        [$service, $connection] = $this->service(
            ready: [$payload],
            delayed: [],
            reserved: [],
        );
        $connection->shouldReceive('eval')->once()->andReturn(1);

        $monitorItem = app(QueueMonitorRepository::class)
            ->findItem('push', (string) $outbox->id);
        self::assertSame(['start'], $monitorItem['available_actions'] ?? null);
        self::assertStringNotContainsString(
            $uuid,
            json_encode($monitorItem, JSON_THROW_ON_ERROR),
        );

        self::assertSame(
            'queued',
            $service->startLinkedOutbox((string) $outbox->id),
        );
        self::assertSame(PushQueueWorkItem::STATUS_QUEUED, $item->refresh()->status);
        self::assertNotNull($outbox->fresh()?->queued_at);
    }

    public function test_linked_outbox_delayed_job_is_moved_to_ready(): void
    {
        $jobId = 'linked-delayed-job-id';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 2);
        [$outbox, $item] = $this->linkedOutbox(
            $jobId,
            $uuid,
            PushQueueWorkItem::STATUS_RETRYING,
        );
        [$service, $connection] = $this->service(
            ready: [],
            delayed: [$payload],
            reserved: [],
        );
        $connection->shouldReceive('eval')->once()->andReturn(1);

        self::assertSame(
            'queued',
            $service->startLinkedOutbox((string) $outbox->id),
        );
        $item->refresh();
        self::assertSame(PushQueueWorkItem::STATUS_QUEUED, $item->status);
        self::assertNull($item->next_attempt_at);
        self::assertNull($item->error_code);
    }

    public function test_linked_outbox_reserved_job_cannot_be_started_twice(): void
    {
        $jobId = 'linked-reserved-job-id';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 1);
        [$outbox, $item] = $this->linkedOutbox(
            $jobId,
            $uuid,
            PushQueueWorkItem::STATUS_QUEUED,
        );
        [$service, $connection] = $this->service(
            ready: [],
            delayed: [],
            reserved: [$payload],
        );
        $connection->shouldNotReceive('eval');

        self::assertSame(
            'conflict',
            $service->startLinkedOutbox((string) $outbox->id),
        );
        self::assertSame(PushQueueWorkItem::STATUS_QUEUED, $item->refresh()->status);
    }

    public function test_failed_standalone_retry_is_unsupported_and_never_touches_redis(): void
    {
        $jobId = 'failed-job-id';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 4);
        $item = $this->workItem($jobId, $uuid, PushQueueWorkItem::STATUS_FAILED);
        DB::table('failed_jobs')->insert([
            'id' => (string) Str::uuid(),
            'uuid' => $uuid,
            'connection' => 'push',
            'queue' => 'push',
            'payload' => $payload,
            'exception' => 'sanitized test exception',
            'failed_at' => now(),
        ]);

        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldNotReceive('connection');
        $service = new PushQueueWorkItemActionService(
            app(PushQueueWorkItemRepository::class),
            $manager,
            app(PushQueueManualActionPolicy::class),
        );

        self::assertSame('unsupported', $service->execute((string) $item->id, 'retry'));
        self::assertDatabaseHas('failed_jobs', [
            'uuid' => $uuid,
            'payload' => $payload,
        ]);
        $item->refresh();
        self::assertSame(PushQueueWorkItem::STATUS_FAILED, $item->status);
        self::assertSame(4, $item->attempts);
        self::assertNotNull($item->finished_at);
        self::assertSame('queue_job_failed', $item->error_code);

        $monitorItem = app(QueueMonitorRepository::class)
            ->findItem('push', (string) $item->id);
        self::assertSame([], $monitorItem['available_actions'] ?? null);
    }

    public function test_state_changing_location_job_cannot_be_prioritized_manually(): void
    {
        $item = $this->workItem(
            'location-sharing-stopped-job-id',
            (string) Str::uuid(),
            PushQueueWorkItem::STATUS_QUEUED,
            'location_sharing_stopped',
        );
        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldNotReceive('connection');
        $service = new PushQueueWorkItemActionService(
            app(PushQueueWorkItemRepository::class),
            $manager,
            app(PushQueueManualActionPolicy::class),
        );

        self::assertSame('conflict', $service->execute((string) $item->id, 'start'));
        self::assertSame(
            [],
            app(QueueMonitorRepository::class)
                ->findItem('push', (string) $item->id)['available_actions'] ?? null,
        );
        self::assertSame(
            PushQueueWorkItem::STATUS_QUEUED,
            $item->refresh()->status,
        );
    }

    public function test_legacy_row_without_job_uuid_has_no_action_and_never_touches_redis(): void
    {
        $item = $this->workItem(
            'legacy-job-id',
            null,
            PushQueueWorkItem::STATUS_RETRYING,
        );
        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldNotReceive('connection');
        $service = new PushQueueWorkItemActionService(
            app(PushQueueWorkItemRepository::class),
            $manager,
            app(PushQueueManualActionPolicy::class),
        );

        self::assertSame('conflict', $service->execute((string) $item->id, 'start'));
        $monitorItem = app(QueueMonitorRepository::class)
            ->findItem('push', (string) $item->id);
        self::assertSame([], $monitorItem['available_actions'] ?? null);
    }

    public function test_monitor_capabilities_never_expose_the_internal_job_uuid(): void
    {
        $jobId = 'monitor-job-id';
        $uuid = (string) Str::uuid();
        $item = $this->workItem($jobId, $uuid, PushQueueWorkItem::STATUS_QUEUED);

        $monitorItem = app(QueueMonitorRepository::class)
            ->findItem('push', (string) $item->id);

        self::assertSame(['start'], $monitorItem['available_actions'] ?? null);
        self::assertArrayNotHasKey('queue_job_uuid', $monitorItem);
        self::assertStringNotContainsString($uuid, json_encode($monitorItem, JSON_THROW_ON_ERROR));
    }

    public function test_tracker_persists_only_the_internal_uuid_from_a_real_queue_payload(): void
    {
        $jobId = 'tracked-job-id';
        $uuid = (string) Str::uuid();
        $rawPayload = $this->payload($jobId, $uuid, attempts: 0);

        app(PushQueueLifecycleTracker::class)->queued(new JobQueued(
            'push',
            'push',
            $jobId,
            new SendFcmNotification(
                (string) Str::ulid(),
                'device_presence_ping',
                'Sensitive title',
                'Sensitive body',
                ['type' => 'device_presence_ping'],
            ),
            $rawPayload,
            null,
        ));

        $item = PushQueueWorkItem::query()->sole();
        self::assertSame($uuid, $item->queue_job_uuid);
        self::assertSame(hash('sha256', $jobId), $item->queue_job_id);
        $serializedItem = $item->toJson();
        self::assertStringNotContainsString('Sensitive', $serializedItem);
        self::assertStringNotContainsString($uuid, $serializedItem);
        self::assertStringNotContainsString(hash('sha256', $jobId), $serializedItem);

        $monitorItem = app(QueueMonitorRepository::class)
            ->findItem('push', (string) $item->id);
        $encoded = json_encode($monitorItem, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($uuid, $encoded);
        self::assertStringNotContainsString($jobId, $encoded);
        self::assertStringNotContainsString('ENCRYPTED-SENSITIVE-PAYLOAD', $encoded);
    }

    public function test_old_failed_job_remains_visible_without_a_retry_action_while_failed_payload_exists(): void
    {
        $jobId = 'old-failed-job-id';
        $uuid = (string) Str::uuid();
        $item = $this->workItem($jobId, $uuid, PushQueueWorkItem::STATUS_FAILED);
        $item->timestamps = false;
        $item->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
        ])->save();
        DB::table('failed_jobs')->insert([
            'id' => (string) Str::uuid(),
            'uuid' => $uuid,
            'connection' => 'push',
            'queue' => 'push',
            'payload' => $this->payload($jobId, $uuid, attempts: 4),
            'exception' => 'sanitized test exception',
            'failed_at' => now()->subDays(2),
        ]);

        $monitorItem = app(QueueMonitorRepository::class)
            ->findItem('push', (string) $item->id);

        self::assertNotNull($monitorItem);
        self::assertSame('failed', $monitorItem['state']);
        self::assertSame([], $monitorItem['available_actions']);
    }

    /**
     * @param  list<string>  $ready
     * @param  list<string>  $delayed
     * @param  list<string>  $reserved
     * @return array{PushQueueWorkItemActionService, Connection, QueueManager}
     */
    private function service(
        array $ready,
        array $delayed,
        array $reserved,
        bool $cluster = false,
    ): array {
        $queueKey = $cluster ? 'queues:{push}' : 'queues:push';
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('isCluster')->andReturn($cluster);
        $connection->shouldReceive('lrange')
            ->with($queueKey, 0, 999)
            ->andReturn($ready);
        $connection->shouldReceive('zrange')
            ->with($queueKey.':delayed', 0, 999)
            ->andReturn($delayed);
        $connection->shouldReceive('zrange')
            ->with($queueKey.':reserved', 0, 999)
            ->andReturn($reserved);

        $redisQueue = Mockery::mock(RedisQueue::class);
        $redisQueue->shouldReceive('getConnection')->andReturn($connection);
        $redisQueue->shouldReceive('getQueue')
            ->with($cluster ? '{push}' : 'push')
            ->andReturn($queueKey);
        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('push')->andReturn($redisQueue);

        return [
            new PushQueueWorkItemActionService(
                app(PushQueueWorkItemRepository::class),
                $manager,
                app(PushQueueManualActionPolicy::class),
            ),
            $connection,
            $manager,
        ];
    }

    private function workItem(
        string $jobId,
        ?string $uuid,
        string $status,
        string $safeMessageType = 'manual_admin',
    ): PushQueueWorkItem {
        return PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', $jobId),
            'queue_job_uuid' => $uuid,
            'safe_message_type' => $safeMessageType,
            'status' => $status,
            'attempts' => $status === PushQueueWorkItem::STATUS_FAILED ? 4 : 0,
            'error_code' => $status === PushQueueWorkItem::STATUS_FAILED
                ? 'queue_job_failed'
                : null,
            'queued_at' => now()->subMinute(),
            'next_attempt_at' => $status === PushQueueWorkItem::STATUS_RETRYING
                ? now()->addMinute()
                : null,
            'finished_at' => $status === PushQueueWorkItem::STATUS_FAILED
                ? now()
                : null,
        ]);
    }

    /** @return array{DispatchPushOutbox, PushQueueWorkItem} */
    private function linkedOutbox(
        string $jobId,
        string $uuid,
        string $status,
    ): array {
        $suffix = strtolower((string) Str::ulid());
        $user = User::query()->create([
            'name' => 'Queue action test',
            'first_name' => 'Queue',
            'last_name' => 'Action',
            'email' => 'queue-action-'.$suffix.'@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
        ]);
        $deployment = Deployment::query()->create([
            'reference' => 'QUEUE-'.$suffix,
            'title' => 'Queue action fixture',
            'priority' => 'normal',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $dispatch = DispatchRequest::query()->create([
            'deployment_id' => $deployment->id,
            'requested_by' => $user->id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Queue action fixture',
        ]);
        $providerToken = 'queue-action-provider-'.$suffix;
        $token = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'queue-action-device-'.$suffix,
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
        ]);
        $outbox = DispatchPushOutbox::query()->create([
            'deduplication_key' => hash('sha256', 'queue-action-outbox-'.$suffix),
            'dispatch_request_id' => $dispatch->id,
            'fcm_token_id' => $token->id,
            'message_type' => 'dispatch_request',
            'title' => 'Queue action fixture',
            'body' => 'Queue action fixture',
            'data' => [],
            'available_at' => now()->subMinutes(2),
            'queued_at' => now()->subMinutes(2),
        ]);
        $item = PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', $jobId),
            'queue_job_uuid' => $uuid,
            'safe_message_type' => 'dispatch_request',
            'dispatch_push_outbox_id' => $outbox->id,
            'status' => $status,
            'attempts' => $status === PushQueueWorkItem::STATUS_RETRYING ? 2 : 0,
            'error_code' => $status === PushQueueWorkItem::STATUS_RETRYING
                ? 'queue_retry_scheduled'
                : null,
            'queued_at' => now()->subMinute(),
            'next_attempt_at' => $status === PushQueueWorkItem::STATUS_RETRYING
                ? now()->addMinute()
                : null,
        ]);

        return [$outbox, $item];
    }

    private function payload(string $jobId, string $uuid, int $attempts): string
    {
        return json_encode([
            'uuid' => $uuid,
            'id' => $jobId,
            'displayName' => SendFcmNotification::class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'attempts' => $attempts,
            'data' => [
                'commandName' => SendFcmNotification::class,
                'command' => 'ENCRYPTED-SENSITIVE-PAYLOAD',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
