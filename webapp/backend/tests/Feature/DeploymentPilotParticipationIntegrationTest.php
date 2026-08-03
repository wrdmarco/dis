<?php

namespace Tests\Feature;

use App\Events\DeploymentChanged;
use App\Jobs\SendFcmNotification;
use App\Models\AvailabilityStatus;
use App\Models\Deployment;
use App\Models\DeploymentPilotAssignment;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\LocationSharingConsent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DeploymentReportService;
use App\Services\DeploymentService;
use App\Services\DispatchService;
use App\Services\WallboardFocusService;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DeploymentPilotParticipationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_participant_has_operational_access_without_becoming_an_alarm_recipient(): void
    {
        Queue::fake();

        $coordinator = $this->user('manual-participant-coordinator@example.test', 'Testcoordinator');
        $this->grant($coordinator, [
            'deployments.view',
            'deployments.dispatch.view',
            'deployments.dispatch.manage',
        ]);
        $pilot = $this->user('manual-participant-pilot@example.test', 'Handmatig Gekoppelde Piloot');
        $this->grant($pilot, ['deployments.assigned.view'], operator: true);
        $deployment = $this->deployment($coordinator, 'dispatching', 'MANUAL-PARTICIPANT-001');
        $this->assignment($deployment, $pilot, $coordinator);
        $this->assertFalse($coordinator->hasPermission('status.override'));

        $this->asOperatorClient($pilot)
            ->getJson('/api/deployments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deployment->id)
            ->assertJsonPath('data.0.active_dispatch', null);

        $this->asOperatorClient($pilot)
            ->getJson('/api/deployments/'.$deployment->id)
            ->assertOk()
            ->assertJsonPath('data.id', $deployment->id)
            ->assertJsonPath('data.active_dispatch', null);

        $this->asWebClient($coordinator)
            ->getJson('/api/deployments/'.$deployment->id.'/live-locations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $pilot->id)
            ->assertJsonPath('data.0.user.id', $pilot->id)
            ->assertJsonPath('data.0.user.name', $pilot->name)
            ->assertJsonPath('data.0.sharing_status', 'not_requested');

        $this->asOperatorClient($pilot)
            ->postJson('/api/deployments/'.$deployment->id.'/location/consent')
            ->assertCreated();
        $this->asOperatorClient($pilot)
            ->postJson('/api/deployments/'.$deployment->id.'/location', [
                'latitude' => 52.100000,
                'longitude' => 5.100000,
                'accuracy_meters' => 4.5,
                'recorded_at' => now()->toIso8601String(),
            ])
            ->assertNoContent();

        $unassignedPilot = $this->user('manual-participant-unassigned@example.test', 'Niet Gekoppelde Piloot');
        $this->asWebClient($coordinator)
            ->postJson('/api/deployments/'.$deployment->id.'/pilots/'.$unassignedPilot->id.'/status', [
                'status' => 'en_route',
                'reason' => 'Niet gekoppeld en dus niet toegestaan.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['user_id']]]);
        $this->assertDatabaseMissing('availability_statuses', [
            'user_id' => $unassignedPilot->id,
        ]);
        $this->asWebClient($coordinator)
            ->postJson('/api/deployments/'.$deployment->id.'/pilots/'.$pilot->id.'/status', [
                'status' => 'available',
                'reason' => 'Niet toegestaan vanuit de inzetdetailpagina.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $this->asWebClient($coordinator)
            ->postJson('/api/deployments/'.$deployment->id.'/pilots/'.$pilot->id.'/status', [
                'status' => 'en_route',
                'reason' => 'Piloot is onderweg naar de inzet.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'en_route');
        $this->assertSame(
            'en_route',
            $pilot->statuses()->latest('effective_at')->latest('id')->value('status'),
        );
        $this->assertSame('dispatching', $deployment->refresh()->status);

        $this->asWebClient($coordinator)
            ->postJson('/api/deployments/'.$deployment->id.'/pilots/'.$pilot->id.'/status', [
                'status' => 'on_scene',
                'reason' => 'Piloot is op locatie bevestigd.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_scene');
        $this->assertSame(
            'on_scene',
            $pilot->statuses()->latest('effective_at')->latest('id')->value('status'),
        );

        $this->assertSame('in_progress', $deployment->refresh()->status);
        $this->assertDatabaseHas('location_sharing_consents', [
            'deployment_id' => $deployment->id,
            'user_id' => $pilot->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('pilot_deployment_reports', [
            'deployment_id' => $deployment->id,
            'user_id' => $pilot->id,
            'status' => 'draft',
        ]);
        $reportData = app(DeploymentReportService::class)->data($deployment->refresh());
        $travelRow = $reportData['travelRows']->sole();
        $this->assertSame('manual', $travelRow['source']);
        $this->assertSame('manual', $travelRow['response_status']);
        $this->assertNull($travelRow['notified_at']);
        $this->assertNull($travelRow['responded_at']);
        $this->assertNotNull($travelRow['en_route_at']);
        $this->assertNotNull($travelRow['on_scene_at']);
        $this->assertSame(0, $reportData['summary']['recipients']);
        $this->assertSame(0, $reportData['summary']['accepted']);
        $this->assertSame(1, $reportData['summary']['en_route']);
        $this->assertSame(1, $reportData['summary']['on_scene']);
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertDatabaseCount('dispatch_recipients', 0);
        $this->assertDatabaseCount('dispatch_push_outbox', 0);

        app(DeploymentService::class)->close(
            $deployment->refresh(),
            $coordinator,
            'Inzet afgerond.',
        );

        $this->assertSame(
            'unavailable',
            $pilot->statuses()
                ->orderByDesc('effective_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('status'),
        );
        $this->asWebClient($coordinator)
            ->getJson('/api/reports/deployments?limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $deployment->id)
            ->assertJsonPath('data.0.recipient_count', 0)
            ->assertJsonPath('data.0.accepted', 0)
            ->assertJsonPath('data.0.expected_pilot_report_count', 1)
            ->assertJsonPath('data.0.missing_pilot_report_count', 1);
        $this->asOperatorClient($pilot)
            ->getJson('/api/deployments/'.$deployment->id)
            ->assertOk()
            ->assertJsonPath('data.id', $deployment->id);
        $this->asWebClient($coordinator)
            ->postJson('/api/deployments/'.$deployment->id.'/pilots/'.$pilot->id.'/status', [
                'status' => 'en_route',
                'reason' => 'Mag niet meer na afronding.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['deployment_id']]]);
    }

    public function test_manual_participant_reuses_sent_dispatch_context_without_getting_a_recipient_row(): void
    {
        Queue::fake();

        $coordinator = $this->user('manual-context-coordinator@example.test', 'Contextcoordinator');
        $manualPilot = $this->user('manual-context-pilot@example.test', 'Handmatige Piloot');
        $otherPilot = $this->user('manual-context-other@example.test', 'Gealarmeerde Piloot');
        $this->grant($manualPilot, ['deployments.assigned.view'], operator: true);
        $deployment = $this->deployment($coordinator, 'dispatching', 'MANUAL-CONTEXT-001');
        $dispatch = $this->sentDispatch($deployment, $coordinator, $otherPilot);
        $this->assignment($deployment, $manualPilot, $coordinator);
        $tokenValue = 'manual-context-token';
        $token = FcmToken::query()->create([
            'user_id' => $manualPilot->id,
            'device_id' => 'manual-context-device',
            'token' => $tokenValue,
            'token_hash' => hash('sha256', $tokenValue),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);

        $this->asOperatorClient($manualPilot)
            ->getJson('/api/deployments/'.$deployment->id)
            ->assertOk()
            ->assertJsonPath('data.active_dispatch.id', $dispatch->id)
            ->assertJsonPath('data.active_dispatch.status', 'sent')
            ->assertJsonPath('data.active_dispatch.response_status', 'accepted');

        $this->assertDatabaseMissing('dispatch_recipients', [
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $manualPilot->id,
        ]);
        $this->assertDatabaseCount('dispatch_recipients', 1);

        $result = app(DispatchService::class)->sendAdditionalInfo(
            $dispatch->refresh(),
            $coordinator,
            'Aanvullende operationele informatie.',
        );
        $this->assertSame(['queued_tokens' => 1, 'recipient_users' => 1], $result);
        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job) use ($dispatch, $token): bool {
            return $job->fcmTokenId === $token->id
                && $job->messageType === 'dispatch_update'
                && ($job->data['action_mode'] ?? null) === 'additional_info'
                && ($job->data['dispatch_id'] ?? null) === $dispatch->id;
        });

        $focus = app(WallboardFocusService::class)->resolve(WallboardConfiguration::defaults());
        $this->assertNotNull($focus);
        $this->assertSame(1, $focus['responses']['counts']['targeted']);
        $this->assertSame(1, $focus['responses']['counts']['contacted']);
        $this->assertSame(1, $focus['responses']['counts']['pending']);
        $this->assertSame(1, $focus['responses']['counts']['accepted']);
        $this->assertSame(
            [$manualPilot->name],
            collect($focus['responses']['coming'])->pluck('name')->all(),
        );
    }

    public function test_scoped_status_accepts_an_accepted_dispatch_participant_but_not_a_pending_recipient(): void
    {
        $statusManager = $this->user('participant-status-manager@example.test', 'Statusmanager');
        $this->grant($statusManager, ['status.override', 'deployments.view']);
        $statusOnlyManager = $this->user('participant-status-only@example.test', 'Statusbeheerder Zonder Inzettoegang');
        $this->grant($statusOnlyManager, ['status.override']);
        $acceptedPilot = $this->user('participant-status-accepted@example.test', 'Geaccepteerde Piloot');
        $pendingPilot = $this->user('participant-status-pending@example.test', 'Wachtende Piloot');
        $deployment = $this->deployment($statusManager, 'dispatching', 'PARTICIPANT-STATUS-001');
        $dispatch = $this->sentDispatch($deployment, $statusManager, $acceptedPilot);
        DispatchRecipient::query()
            ->where('dispatch_request_id', $dispatch->id)
            ->where('user_id', $acceptedPilot->id)
            ->update([
                'response_status' => 'accepted',
                'responded_at' => now(),
            ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pendingPilot->id,
            'user_name' => $pendingPilot->name,
            'user_email' => $pendingPilot->email,
            'response_status' => 'pending',
            'notified_at' => now(),
        ]);

        $this->asWebClient($statusOnlyManager)
            ->postJson('/api/deployments/'.$deployment->id.'/pilots/'.$acceptedPilot->id.'/status', [
                'status' => 'en_route',
                'reason' => 'Geen toegang tot de inzet.',
            ])
            ->assertForbidden();
        $this->asWebClient($statusManager)
            ->postJson('/api/deployments/'.$deployment->id.'/pilots/'.$acceptedPilot->id.'/status', [
                'status' => 'en_route',
                'reason' => 'Geaccepteerde piloot is onderweg.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'en_route');
        $this->asWebClient($statusManager)
            ->postJson('/api/deployments/'.$deployment->id.'/pilots/'.$pendingPilot->id.'/status', [
                'status' => 'en_route',
                'reason' => 'Nog niet geaccepteerd.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['user_id']]]);
    }

    public function test_unlink_reconciles_remaining_participants_and_defers_location_broadcast_until_outer_commit(): void
    {
        $manager = $this->user('unlink-reconcile-manager@example.test', 'Koppelingsbeheerder');
        $this->grant($manager, [
            'deployments.view',
            'deployments.dispatch.view',
            'deployments.dispatch.manage',
        ]);
        $remainingPilot = $this->user('unlink-reconcile-remaining@example.test', 'Piloot Op Locatie');
        $removedPilot = $this->user('unlink-reconcile-removed@example.test', 'Piloot Onderweg');
        $deployment = $this->deployment($manager, 'dispatching', 'UNLINK-RECONCILE-001');
        $this->assignment($deployment, $remainingPilot, $manager);
        $removedAssignment = $this->assignment($deployment, $removedPilot, $manager);

        AvailabilityStatus::query()->create([
            'user_id' => $remainingPilot->id,
            'user_name' => $remainingPilot->name,
            'user_email' => $remainingPilot->email,
            'status' => 'on_scene',
            'is_available' => false,
            'effective_at' => now(),
        ]);
        AvailabilityStatus::query()->create([
            'user_id' => $removedPilot->id,
            'user_name' => $removedPilot->name,
            'user_email' => $removedPilot->email,
            'status' => 'en_route',
            'is_available' => false,
            'effective_at' => now(),
        ]);
        LocationSharingConsent::query()->create([
            'deployment_id' => $deployment->id,
            'user_id' => $removedPilot->id,
            'is_active' => true,
            'state_version' => 1,
            'consented_at' => now(),
        ]);

        Event::fake([DeploymentChanged::class]);

        DB::transaction(function () use ($manager, $deployment, $removedAssignment, $removedPilot): void {
            $this->asWebClient($manager)
                ->deleteJson("/api/deployments/{$deployment->id}/pilots/{$removedAssignment->id}")
                ->assertNoContent();

            $this->assertSame('in_progress', $deployment->refresh()->status);
            $this->assertDatabaseHas('location_sharing_consents', [
                'deployment_id' => $deployment->id,
                'user_id' => $removedPilot->id,
                'is_active' => false,
            ]);
            Event::assertDispatched(
                DeploymentChanged::class,
                fn (DeploymentChanged $event): bool => $event->action === 'pilot_manual_link_removed',
            );
            Event::assertNotDispatched(
                DeploymentChanged::class,
                fn (DeploymentChanged $event): bool => $event->action === 'location_sharing_changed',
            );
        });

        Event::assertDispatched(
            DeploymentChanged::class,
            fn (DeploymentChanged $event): bool => $event->action === 'location_sharing_changed'
                && $event->deployment->status === 'in_progress',
        );
        $this->assertDatabaseMissing('deployment_pilot_assignments', ['id' => $removedAssignment->id]);
        $this->assertDatabaseHas('deployment_status_history', [
            'deployment_id' => $deployment->id,
            'from_status' => 'dispatching',
            'to_status' => 'in_progress',
            'changed_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deployments.status_auto_updated',
            'target_id' => $deployment->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'location.consent_revoked',
            'target_id' => $deployment->id,
        ]);
    }

    public function test_deleted_dispatch_participants_are_preserved_per_recipient(): void
    {
        $manager = $this->user('deleted-recipients-manager@example.test', 'Verwijderde Piloten Beheerder');
        $this->grant($manager, ['deployments.view', 'deployments.dispatch.view']);
        $deployment = $this->deployment($manager, 'dispatching', 'DELETED-RECIPIENTS-001');
        $dispatch = DispatchRequest::query()->create([
            'deployment_id' => $deployment->id,
            'requested_by' => $manager->id,
            'requested_by_name' => $manager->name,
            'requested_by_email' => $manager->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Historische alarmering.',
            'sent_at' => now(),
        ]);

        $firstPilot = $this->user('deleted-pilot-one@example.test', 'Verwijderde Piloot Een');
        $secondPilot = $this->user('deleted-pilot-two@example.test', 'Verwijderde Piloot Twee');

        $firstRecipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $firstPilot->id,
            'user_name' => $firstPilot->name,
            'user_email' => $firstPilot->email,
            'response_status' => 'accepted',
            'notified_at' => now()->subMinute(),
            'responded_at' => now(),
        ]);
        $secondRecipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $secondPilot->id,
            'user_name' => $secondPilot->name,
            'user_email' => $secondPilot->email,
            'response_status' => 'accepted',
            'notified_at' => now()->subMinute(),
            'responded_at' => now(),
        ]);
        $firstPilot->forceDelete();
        $secondPilot->forceDelete();

        $response = $this->asWebClient($manager)
            ->getJson("/api/deployments/{$deployment->id}/pilots")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $participants = collect($response->json('data'));

        $this->assertEqualsCanonicalizing(
            [$firstRecipient->id, $secondRecipient->id],
            $participants->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['Verwijderde Piloot Een', 'Verwijderde Piloot Twee'],
            $participants->pluck('user.name')->all(),
        );
        $this->assertSame([null, null], $participants->pluck('user_id')->all());
        $this->assertSame([null, null], $participants->pluck('user.id')->all());
        $this->assertSame(['dispatch', 'dispatch'], $participants->pluck('source')->all());
    }

    private function user(string $email, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'first_name' => str($name)->before(' ')->toString(),
            'last_name' => str($name)->after(' ')->toString(),
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => false,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @param list<string> $permissionNames */
    private function grant(User $user, array $permissionNames, bool $operator = false): void
    {
        $role = Role::query()->create([
            'name' => 'manual-participation-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Manual participation integration role',
            'can_use_operator_app' => $operator,
            'can_use_admin_app' => ! $operator,
        ]);
        $permissions = collect($permissionNames)->map(fn (string $name): Permission => Permission::query()->firstOrCreate(
            ['name' => $name],
            [
                'category' => 'test',
                'display_name' => $name,
                'description' => 'Manual participation integration permission',
            ],
        ));
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function deployment(User $creator, string $status, string $reference): Deployment
    {
        return Deployment::query()->create([
            'reference' => $reference,
            'title' => 'Handmatige pilotenkoppeling',
            'description' => 'Integratietest voor een handmatig gekoppelde piloot.',
            'priority' => 'normal',
            'status' => $status,
            'is_test' => false,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
        ]);
    }

    private function assignment(
        Deployment $deployment,
        User $pilot,
        User $coordinator,
    ): DeploymentPilotAssignment {
        return DeploymentPilotAssignment::query()->create([
            'deployment_id' => $deployment->id,
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'assigned_by' => $coordinator->id,
            'assigned_by_name' => $coordinator->name,
            'assigned_by_email' => $coordinator->email,
            'reason' => 'Piloot sluit zonder alarmering aan bij deze inzet.',
            'assigned_at' => now(),
        ]);
    }

    private function sentDispatch(
        Deployment $deployment,
        User $coordinator,
        User $recipient,
    ): DispatchRequest {
        $dispatch = DispatchRequest::query()->create([
            'deployment_id' => $deployment->id,
            'requested_by' => $coordinator->id,
            'requested_by_name' => $coordinator->name,
            'requested_by_email' => $coordinator->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Alarm voor een andere piloot.',
            'sent_at' => now(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $recipient->id,
            'user_name' => $recipient->name,
            'user_email' => $recipient->email,
            'response_status' => 'pending',
            'notified_at' => now(),
        ]);

        return $dispatch;
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken('Manual participation web test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function asOperatorClient(User $user): static
    {
        $token = $user->createToken('Manual participation operator test', ['*', 'client:operator'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
