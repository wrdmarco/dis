<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\PushQueueWorkItem;
use App\Services\PushQueueWorkItemActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class PushQueueWorkItemActionRedisIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const QUEUE_KEY = 'queues:push';

    private ?Connection $redis = null;

    /** @var list<string> */
    private array $cleanupKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            self::markTestSkipped(
                'De echte Redis-integratietest vereist de productie-extensie phpredis.',
            );
        }

        $connectionName = 'queue_action_integration';
        $redisConfig = config('database.redis');
        $redisConfig[$connectionName] = array_merge(
            $redisConfig['default'],
            ['prefix' => 'dis_queue_action_test_'.Str::lower((string) Str::ulid()).':'],
        );
        $this->app->instance('redis', new RedisManager(
            $this->app,
            'phpredis',
            $redisConfig,
        ));
        config([
            'queue.connections.push.connection' => $connectionName,
        ]);

        $queue = app(QueueManager::class)->connection('push');
        if (! $queue instanceof RedisQueue) {
            self::fail('De pushverbinding moet tijdens deze test Redis gebruiken.');
        }

        try {
            $this->redis = $queue->getConnection();
            $this->redis->ping();
        } catch (Throwable) {
            self::markTestSkipped(
                'Geen lokale Redis-server beschikbaar voor de echte Lua-integratietest.',
            );
        }

        $this->cleanupKeys = [
            self::QUEUE_KEY,
            self::QUEUE_KEY.':delayed',
            self::QUEUE_KEY.':reserved',
            self::QUEUE_KEY.':notify',
        ];
        $this->deleteRedisKeys($this->cleanupKeys);
    }

    protected function tearDown(): void
    {
        $this->deleteRedisKeys($this->cleanupKeys);
        $this->redis = null;

        parent::tearDown();
    }

    public function test_real_redis_lua_moves_a_ready_job_to_the_front(): void
    {
        $jobId = 'redis-ready-job';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 0);
        $otherPayload = $this->payload(
            'redis-ready-other-job',
            (string) Str::uuid(),
            attempts: 0,
        );
        $item = $this->workItem(
            $jobId,
            $uuid,
            PushQueueWorkItem::STATUS_QUEUED,
        );
        $this->redis()->rpush(self::QUEUE_KEY, $otherPayload, $payload);

        self::assertSame(
            'queued',
            app(PushQueueWorkItemActionService::class)->execute(
                (string) $item->id,
                'start',
            ),
        );

        self::assertSame(
            [$payload, $otherPayload],
            $this->redis()->lrange(self::QUEUE_KEY, 0, -1),
        );
        self::assertSame(0, $this->redis()->zcard(self::QUEUE_KEY.':delayed'));
        self::assertSame(0, $this->redis()->llen(self::QUEUE_KEY.':notify'));
    }

    public function test_real_redis_lua_moves_a_delayed_job_and_notifies_a_worker(): void
    {
        $jobId = 'redis-delayed-job';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 2);
        $item = $this->workItem(
            $jobId,
            $uuid,
            PushQueueWorkItem::STATUS_RETRYING,
        );
        $this->redis()->zadd(
            self::QUEUE_KEY.':delayed',
            now()->addMinute()->getTimestamp(),
            $payload,
        );

        self::assertSame(
            'queued',
            app(PushQueueWorkItemActionService::class)->execute(
                (string) $item->id,
                'start',
            ),
        );

        self::assertSame(
            [$payload],
            $this->redis()->lrange(self::QUEUE_KEY, 0, -1),
        );
        self::assertSame(0, $this->redis()->zcard(self::QUEUE_KEY.':delayed'));
        self::assertSame(1, $this->redis()->llen(self::QUEUE_KEY.':notify'));
        self::assertSame(
            PushQueueWorkItem::STATUS_QUEUED,
            $item->refresh()->status,
        );
        self::assertNull($item->next_attempt_at);
    }

    public function test_real_redis_lua_reports_an_invalid_notify_key_type_without_mutation(): void
    {
        $jobId = 'redis-invalid-notify-type-job';
        $uuid = (string) Str::uuid();
        $payload = $this->payload($jobId, $uuid, attempts: 0);
        $item = $this->workItem(
            $jobId,
            $uuid,
            PushQueueWorkItem::STATUS_QUEUED,
        );
        $this->redis()->rpush(self::QUEUE_KEY, $payload);
        $this->redis()->set(self::QUEUE_KEY.':notify', 'invalid-string-type');

        self::assertSame(
            'failed',
            app(PushQueueWorkItemActionService::class)->execute(
                (string) $item->id,
                'start',
            ),
        );

        self::assertSame(
            [$payload],
            $this->redis()->lrange(self::QUEUE_KEY, 0, -1),
        );
        self::assertSame(
            'invalid-string-type',
            $this->redis()->get(self::QUEUE_KEY.':notify'),
        );
        self::assertSame(PushQueueWorkItem::STATUS_QUEUED, $item->refresh()->status);
    }

    private function redis(): Connection
    {
        return $this->redis
            ?? throw new \LogicException('Redis is niet geïnitialiseerd.');
    }

    /** @param list<string> $keys */
    private function deleteRedisKeys(array $keys): void
    {
        if ($this->redis === null || $keys === []) {
            return;
        }

        try {
            $this->redis->del(...array_values(array_unique($keys)));
        } catch (Throwable) {
            // The test was already skipped when Redis became unavailable.
        }
    }

    private function workItem(
        string $jobId,
        string $uuid,
        string $status,
    ): PushQueueWorkItem {
        return PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', $jobId),
            'queue_job_uuid' => $uuid,
            'safe_message_type' => 'manual_admin',
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
