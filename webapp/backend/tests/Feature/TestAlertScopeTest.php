<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\AuditLog;
use App\Models\DispatchRecipient;
use App\Models\FcmToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
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
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $actorToken->id);
        Queue::assertNotPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $otherToken->id);

        $this->asWebClient($actor)
            ->getJson('/api/test-alert')
            ->assertOk()
            ->assertJsonPath('data.id', $response->json('data.id'));
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

    public function test_all_online_does_not_claim_success_when_every_selected_recipient_fails(): void
    {
        Queue::fake();
        $actor = $this->user('failed-coordinator@example.test');
        $this->grant($actor, ['incidents.dispatch.manage'], operator: false, admin: true);

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

        $response->assertUnprocessable()
            ->assertJsonPath(
                'error.details.recipients.0',
                'De proefalarmering kon voor geen enkele operator-app worden klaargezet.',
            );
        $this->assertDatabaseHas('dispatch_requests', ['status' => 'cancelled']);
        $this->assertDatabaseHas('incidents', ['status' => 'cancelled', 'is_test' => true]);
        $this->assertDatabaseCount('dispatch_recipients', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'test_alert.sent']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'test_alert.not_sent']);
        Queue::assertNothingPushed();
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
            ['2fa:pending', 'client:admin'],
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

    private function asWebClient(User $user): static
    {
        Auth::forgetGuards();
        $token = $user->createToken('Test alert scope', ['*', 'client:web'], now()->addHour())->plainTextToken;

        return $this->flushHeaders()->withToken($token);
    }
}
