<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PushQueueWorkItem;
use App\Models\Role;
use App\Models\User;
use App\Services\WebSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class PushQueueWorkItemActionApiTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_ORIGIN = 'https://dis.example.test';

    public function test_manager_starts_standalone_push_job_without_exposing_transport_identity(): void
    {
        $jobId = 'standalone-api-raw-redis-id';
        $uuid = (string) Str::uuid();
        $encryptedPayload = 'ENCRYPTED-SENSITIVE-PAYLOAD';
        $payload = json_encode([
            'uuid' => $uuid,
            'id' => $jobId,
            'displayName' => SendFcmNotification::class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'attempts' => 0,
            'data' => [
                'commandName' => SendFcmNotification::class,
                'command' => $encryptedPayload,
            ],
        ], JSON_THROW_ON_ERROR);
        $item = PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', $jobId),
            'queue_job_uuid' => $uuid,
            'safe_message_type' => 'manual_admin',
            'status' => PushQueueWorkItem::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
        $this->mockReadyPushTransport($payload);
        $manager = $this->manager();

        $response = $this->asWebSession($manager)
            ->postJson('/api/admin/queues/push/'.$item->id.'/start')
            ->assertStatus(202)
            ->assertJsonPath('data.action', 'started')
            ->assertJsonMissingPath('data.item');

        self::assertSame(
            PushQueueWorkItem::STATUS_QUEUED,
            $item->refresh()->status,
        );
        $audit = AuditLog::query()
            ->where('action', 'system.queue.started')
            ->where('target_type', PushQueueWorkItem::class)
            ->where('target_id', $item->id)
            ->sole();
        $requestAudit = AuditLog::query()
            ->where('action', 'system.queue.action_requested')
            ->where('target_type', PushQueueWorkItem::class)
            ->where('target_id', $item->id)
            ->sole();
        self::assertSame((string) $manager->id, (string) $requestAudit->actor_id);
        self::assertSame('queued', $requestAudit->metadata['previous_state'] ?? null);
        self::assertSame('start', $requestAudit->metadata['requested_action'] ?? null);
        self::assertSame((string) $manager->id, (string) $audit->actor_id);
        self::assertSame('queued', $audit->metadata['previous_state'] ?? null);
        self::assertSame('started', $audit->metadata['outcome'] ?? null);

        foreach ([
            $response->getContent(),
            json_encode($requestAudit->metadata, JSON_THROW_ON_ERROR),
            json_encode($audit->metadata, JSON_THROW_ON_ERROR),
        ] as $encoded) {
            self::assertStringNotContainsString($uuid, $encoded);
            self::assertStringNotContainsString($jobId, $encoded);
            self::assertStringNotContainsString(hash('sha256', $jobId), $encoded);
            self::assertStringNotContainsString($encryptedPayload, $encoded);
            self::assertStringNotContainsString('queue_job_uuid', $encoded);
        }
    }

    private function mockReadyPushTransport(string $payload): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('isCluster')->andReturnFalse();
        $connection->shouldReceive('lrange')
            ->with('queues:push', 0, 999)
            ->once()
            ->andReturn([$payload]);
        $connection->shouldReceive('zrange')
            ->with('queues:push:delayed', 0, 999)
            ->once()
            ->andReturn([]);
        $connection->shouldReceive('zrange')
            ->with('queues:push:reserved', 0, 999)
            ->once()
            ->andReturn([]);
        $connection->shouldReceive('eval')->once()->andReturn(1);

        $redisQueue = Mockery::mock(RedisQueue::class);
        $redisQueue->shouldReceive('getConnection')->once()->andReturn($connection);
        $redisQueue->shouldReceive('getQueue')
            ->with('push')
            ->once()
            ->andReturn('queues:push');
        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')
            ->with('push')
            ->once()
            ->andReturn($redisQueue);
        $this->app->instance(QueueManager::class, $manager);
    }

    private function manager(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'system.queues.manage'],
            [
                'display_name' => 'Wachtrijtaken beheren',
                'category' => 'system_configuration',
                'description' => 'Wachtrijtaken beheren',
            ],
        );
        $role = Role::query()->create([
            'name' => 'queue-action-manager-'.strtolower((string) Str::ulid()),
            'display_name' => 'Queue action manager',
            'description' => 'Queue action test role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        $role->permissions()->attach($permission->id, ['created_at' => now()]);
        $manager = User::query()->create([
            'name' => 'Queue action manager',
            'first_name' => 'Queue',
            'last_name' => 'Manager',
            'email' => 'queue-action-api@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $manager->roles()->attach($role->id, ['created_at' => now()]);

        return $manager;
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
        $csrfToken = hash('sha256', 'queue-action-browser-session-'.$user->id);

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
                'REMOTE_ADDR' => '192.0.2.71',
            ]);
    }
}
