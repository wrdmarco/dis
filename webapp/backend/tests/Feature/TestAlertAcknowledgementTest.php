<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\AuditLog;
use App\Models\AvailabilityStatus;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TestAlertAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_operator_recipient_can_acknowledge_test_alert_without_operational_side_effects(): void
    {
        Queue::fake();

        $coordinator = $this->user('test-coordinator@example.test');
        $pilot = $this->user('test-pilot@example.test');
        $this->grantOperatorPermission($pilot, 'incidents.assigned.view');
        $token = $this->operatorToken($pilot);
        [$incident, $dispatch, $recipient] = $this->createTestDispatch($coordinator, $pilot);
        $effectiveAt = now()->subMinute()->startOfSecond();
        $availability = AvailabilityStatus::query()->create([
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'status' => 'on_scene',
            'is_available' => true,
            'is_system_applied' => false,
            'changed_by' => $pilot->id,
            'changed_by_name' => $pilot->name,
            'changed_by_email' => $pilot->email,
            'reason' => 'Bestaande operationele status',
            'effective_at' => $effectiveAt,
        ]);

        $this->asOperator($pilot)
            ->postJson('/api/dispatches/'.$dispatch->id.'/respond', [
                'response' => 'accepted',
                'note' => 'Proefalarm zichtbaar ontvangen.',
            ])
            ->assertNoContent();

        $recipient->refresh();
        $this->assertSame('accepted', $recipient->response_status);
        $this->assertSame('Proefalarm zichtbaar ontvangen.', $recipient->response_note);
        $this->assertNotNull($recipient->responded_at);

        $this->assertSame('active', $incident->refresh()->status);
        $this->assertDatabaseCount('availability_statuses', 1);
        $availability->refresh();
        $this->assertSame('on_scene', $availability->status);
        $this->assertTrue($availability->is_available);
        $this->assertSame('Bestaande operationele status', $availability->reason);
        $this->assertTrue($availability->effective_at->equalTo($effectiveAt));

        $audit = AuditLog::query()
            ->where('action', 'dispatch.responded')
            ->where('actor_id', $pilot->id)
            ->where('target_id', $dispatch->id)
            ->firstOrFail();
        $this->assertSame('accepted', $audit->metadata['response'] ?? null);
        $this->assertSame('test_ack', $audit->metadata['action_mode'] ?? null);

        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job) use ($dispatch, $incident, $token): bool {
            return $job->fcmTokenId === $token->id
                && $job->messageType === 'dispatch_response_sync'
                && $job->dispatchRequestId === $dispatch->id
                && ($job->data['dispatch_id'] ?? null) === $dispatch->id
                && ($job->data['incident_id'] ?? null) === $incident->id
                && ($job->data['action_mode'] ?? null) === 'test_ack'
                && ($job->data['is_test'] ?? null) === 'true'
                && ($job->data['response'] ?? null) === 'accepted';
        });
    }

    public function test_operator_with_assigned_permission_cannot_acknowledge_a_test_alert_for_another_recipient(): void
    {
        Queue::fake();

        $coordinator = $this->user('other-test-coordinator@example.test');
        $assignedPilot = $this->user('assigned-test-pilot@example.test');
        $otherPilot = $this->user('other-test-pilot@example.test');
        $this->grantOperatorPermission($otherPilot, 'incidents.assigned.view');
        [$incident, $dispatch, $recipient] = $this->createTestDispatch($coordinator, $assignedPilot);

        $this->asOperator($otherPilot)
            ->postJson('/api/dispatches/'.$dispatch->id.'/respond', [
                'response' => 'accepted',
                'note' => 'Mag niet worden opgeslagen.',
            ])
            ->assertForbidden();

        $recipient->refresh();
        $this->assertSame('pending', $recipient->response_status);
        $this->assertNull($recipient->response_note);
        $this->assertNull($recipient->responded_at);
        $this->assertSame('active', $incident->refresh()->status);
        $this->assertFalse(AuditLog::query()
            ->where('action', 'dispatch.responded')
            ->where('actor_id', $otherPilot->id)
            ->where('target_id', $dispatch->id)
            ->exists());
        Queue::assertNothingPushed();
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Test Pilot',
            'first_name' => 'Test',
            'last_name' => 'Pilot',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function grantOperatorPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => $permissionName],
            [
                'category' => 'test',
                'display_name' => $permissionName,
                'description' => 'Test permission',
            ],
        );
        $role = Role::query()->create([
            'name' => 'test-operator-'.strtolower((string) str()->ulid()),
            'display_name' => 'Test Operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $role->permissions()->attach($permission->id, ['created_at' => now()]);
        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function operatorToken(User $user): FcmToken
    {
        $value = 'test-fcm-token-'.strtolower((string) str()->ulid());

        return FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'test-device-'.strtolower((string) str()->ulid()),
            'token' => $value,
            'token_hash' => hash('sha256', $value),
            'platform' => 'android',
            'client_type' => 'operator',
            'app_version' => 'test',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @return array{Incident, DispatchRequest, DispatchRecipient}
     */
    private function createTestDispatch(User $coordinator, User $recipientUser): array
    {
        $incident = Incident::query()->create([
            'reference' => 'TEST-ACK-'.strtoupper(substr((string) str()->ulid(), -8)),
            'title' => 'Proefalarmering',
            'description' => 'Gerichte integratietest',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => true,
            'created_by' => $coordinator->id,
            'created_by_name' => $coordinator->name,
            'created_by_email' => $coordinator->email,
            'coordinator_id' => $coordinator->id,
            'coordinator_name' => $coordinator->name,
            'coordinator_email' => $coordinator->email,
            'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $coordinator->id,
            'requested_by_name' => $coordinator->name,
            'requested_by_email' => $coordinator->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Dit is een proefalarmering.',
            'sent_at' => now(),
        ]);
        $recipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $recipientUser->id,
            'user_name' => $recipientUser->name,
            'user_email' => $recipientUser->email,
            'response_status' => 'pending',
            'notified_at' => now(),
        ]);

        return [$incident, $dispatch, $recipient];
    }

    private function asOperator(User $user): static
    {
        $token = $user->createToken('Test alert operator', ['*', 'client:operator'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
