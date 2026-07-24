<?php

namespace Tests\Feature;

use App\Contracts\DispatchNotificationQueue;
use App\Models\AuditLog;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DispatchPushOutboxService;
use App\Services\WebSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class QueueActionSynchronousCompletionTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_ORIGIN = 'https://dis.example.test';

    public function test_start_remains_successful_and_audited_when_enqueue_synchronously_cancels_outbox(): void
    {
        [$manager, $outbox] = $this->fixture();
        $synchronousQueue = new class implements DispatchNotificationQueue
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
                app(DispatchPushOutboxService::class)->markTerminal(
                    (string) $notification->id,
                    (string) $notification->fcm_token_id,
                    'provider_rejected',
                );
            }
        };
        $this->app->instance(DispatchNotificationQueue::class, $synchronousQueue);

        $this->asWebSession($manager)
            ->postJson('/api/admin/queues/push/'.$outbox->id.'/start')
            ->assertStatus(202)
            ->assertJsonPath('data.action', 'started')
            ->assertJsonMissingPath('data.item');

        $this->assertSame(1, $synchronousQueue->enqueueCount);
        $this->assertTrue($synchronousQueue->requestAuditSeenBeforeEnqueue);
        $this->assertNotNull($outbox->fresh()?->cancelled_at);
        $this->assertSame('provider_rejected', $outbox->fresh()?->last_error_code);

        $requestAudit = AuditLog::query()
            ->where('action', 'system.queue.action_requested')
            ->where('target_id', $outbox->id)
            ->firstOrFail();
        $audit = AuditLog::query()
            ->where('action', 'system.queue.started')
            ->where('target_id', $outbox->id)
            ->firstOrFail();
        $this->assertSame((string) $manager->id, (string) $requestAudit->actor_id);
        $this->assertSame('start', $requestAudit->metadata['requested_action'] ?? null);
        $this->assertSame((string) $manager->id, (string) $audit->actor_id);
        $this->assertSame('started', $audit->metadata['outcome'] ?? null);
    }

    /** @return array{User, DispatchPushOutbox} */
    private function fixture(): array
    {
        $manager = User::query()->create([
            'name' => 'Queue Race Manager',
            'first_name' => 'Queue',
            'last_name' => 'Race Manager',
            'email' => 'queue-race-manager@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'queue-race-manager-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Queue race manager',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach (['system.health.view', 'system.queues.manage'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'Queue action race test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $manager->roles()->attach($role->id, ['created_at' => now()]);

        $incident = Incident::query()->create([
            'reference' => 'QUEUE-RACE',
            'title' => 'Queue race regression',
            'priority' => 'normal',
            'status' => 'active',
            'created_by' => $manager->id,
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $manager->id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Queue race regression',
        ]);
        $providerToken = 'queue-race-provider-token';
        $token = FcmToken::query()->create([
            'user_id' => $manager->id,
            'device_id' => 'queue-race-device',
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
        ]);
        $outbox = DispatchPushOutbox::query()->create([
            'deduplication_key' => hash('sha256', 'queue-race-outbox'),
            'dispatch_request_id' => $dispatch->id,
            'fcm_token_id' => $token->id,
            'message_type' => 'dispatch_update',
            'title' => 'Queue race regression',
            'body' => 'Queue race regression',
            'data' => [],
            'available_at' => now()->addHour(),
        ]);

        return [$manager, $outbox];
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
        $csrfToken = hash('sha256', 'queue-race-browser-session-'.$user->id);

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
