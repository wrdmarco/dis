<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\AuditLog;
use App\Models\AvailabilityStatus;
use App\Models\Deployment;
use App\Models\DeploymentPilotAssignment;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DeploymentPilotAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_links_and_unlinks_pilot_with_informational_push_without_dispatch_artifacts(): void
    {
        Queue::fake();
        $manager = $this->manager('pilot-link-manager@example.test');
        [$pilotRole, $ocp] = $this->pilotStructure();
        $pilot = $this->pilot('linked-pilot@example.test', $pilotRole, $ocp, 'Gekoppelde Piloot');
        $pilot->forceFill(['push_enabled' => true])->save();
        $pilot->statuses()->create([
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'status' => 'available',
            'is_available' => true,
            'effective_at' => now(),
        ]);
        $operatorAccessToken = $pilot->createToken(
            'Manual deployment assignment',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;
        $device = FcmToken::query()->create([
            'user_id' => $pilot->id,
            'device_id' => 'manual-assignment-device',
            'token' => 'manual-assignment-provider-token',
            'token_hash' => hash('sha256', 'manual-assignment-provider-token'),
            'personal_access_token_id' => $operatorAccessToken->id,
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $deployment = $this->deployment($manager, 'active', false, 'PILOT-LINK-001');

        $candidateResponse = $this->asWebClient($manager)
            ->getJson("/api/deployments/{$deployment->id}/pilot-candidates")
            ->assertOk()
            ->assertJsonPath('data.0.id', $pilot->id)
            ->assertJsonPath('data.0.teams.0.code', 'OCP')
            ->assertJsonPath('data.0.statuses.0.status', 'available');
        $this->assertCount(1, $candidateResponse->json('data'));

        $response = $this->asWebClient($manager)
            ->postJson("/api/deployments/{$deployment->id}/pilots", [
                'user_id' => $pilot->id,
                'reason' => 'Piloot meldt zich rechtstreeks bij de meldkamer.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $pilot->id)
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.user.name', 'Gekoppelde Piloot')
            ->assertJsonPath('data.user.teams.0.code', 'OCP')
            ->assertJsonPath('data.user.statuses.0.status', 'available')
            ->assertJsonPath('data.notification_queued_tokens', 1);

        $assignmentId = (string) $response->json('data.id');
        $this->assertDatabaseHas('deployment_pilot_assignments', [
            'id' => $assignmentId,
            'deployment_id' => $deployment->id,
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'assigned_by' => $manager->id,
            'reason' => 'Piloot meldt zich rechtstreeks bij de meldkamer.',
        ]);
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertDatabaseCount('dispatch_recipients', 0);
        $this->assertDatabaseCount('dispatch_push_outbox', 0);

        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job) use ($deployment, $device): bool {
            $this->assertSame($device->id, $job->fcmTokenId);
            $this->assertSame('manual_admin', $job->messageType);
            $this->assertSame('Aan inzet gekoppeld', $job->title);
            $this->assertSame(
                "Je bent gekoppeld aan inzet {$deployment->reference}. Open D.I.S. voor de details.",
                $job->body,
            );
            $this->assertSame('manual_admin', $job->data['type'] ?? null);
            $this->assertSame('manual_admin', $job->data['deployment_event_type'] ?? null);
            $this->assertSame($deployment->id, $job->data['deployment_id'] ?? null);
            $this->assertSame($deployment->id, $job->data['incident_id'] ?? null);
            $this->assertSame($deployment->reference, $job->data['deployment_reference'] ?? null);
            $this->assertSame($deployment->reference, $job->data['incident_reference'] ?? null);
            $this->assertArrayNotHasKey('dispatch_id', $job->data);
            $this->assertArrayNotHasKey('action_mode', $job->data);
            $this->assertNull($job->dispatchRequestId);
            $this->assertNull($job->dispatchPushOutboxId);

            return true;
        });
        Queue::assertPushed(SendFcmNotification::class, 1);

        $linkAudit = AuditLog::query()
            ->where('action', 'deployments.pilot_manually_linked')
            ->where('target_id', $deployment->id)
            ->sole();
        $this->assertSame($assignmentId, $linkAudit->metadata['assignment_id'] ?? null);
        $this->assertSame($pilot->id, $linkAudit->metadata['user_id'] ?? null);
        $this->assertSame('Piloot meldt zich rechtstreeks bij de meldkamer.', $linkAudit->reason);
        $notificationAudit = AuditLog::query()
            ->where('action', 'deployments.pilot_manual_notification_queued')
            ->where('target_id', $deployment->id)
            ->sole();
        $this->assertSame(1, $notificationAudit->metadata['queued_devices'] ?? null);
        $timelineLabels = collect($this->asWebClient($manager)
            ->getJson("/api/deployments/{$deployment->id}/timeline")
            ->assertOk()
            ->json('data'))
            ->pluck('label');
        $this->assertTrue($timelineLabels->contains('Piloot handmatig gekoppeld'));
        $this->assertTrue($timelineLabels->contains('Koppelingsmelding ingepland'));

        $this->asWebClient($manager)
            ->getJson("/api/deployments/{$deployment->id}/pilots")
            ->assertOk()
            ->assertJsonPath('data.0.id', $assignmentId)
            ->assertJsonPath('data.0.source', 'manual');

        $this->asWebClient($manager)
            ->deleteJson("/api/deployments/{$deployment->id}/pilots/{$assignmentId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('deployment_pilot_assignments', ['id' => $assignmentId]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deployments.pilot_manual_link_removed',
            'target_id' => $deployment->id,
        ]);
        $timelineLabels = collect($this->asWebClient($manager)
            ->getJson("/api/deployments/{$deployment->id}/timeline")
            ->assertOk()
            ->json('data'))
            ->pluck('label');
        $this->assertTrue($timelineLabels->contains('Handmatige pilootkoppeling verwijderd'));
    }

    public function test_candidate_filter_and_combined_list_keep_manual_and_dispatch_sources_distinct(): void
    {
        Queue::fake();
        $manager = $this->manager('pilot-list-manager@example.test');
        [$pilotRole, $ocp] = $this->pilotStructure();
        $candidate = $this->pilot('candidate@example.test', $pilotRole, $ocp, 'Beschikbare Kandidaat');
        $manualPilot = $this->pilot('manual@example.test', $pilotRole, $ocp, 'Handmatige Piloot');
        $dispatchPilot = $this->pilot('dispatch@example.test', $pilotRole, $ocp, 'Gealarmeerde Piloot');
        $inactivePilot = $this->pilot('inactive@example.test', $pilotRole, $ocp, 'Inactieve Piloot');
        $inactivePilot->forceFill(['account_status' => 'suspended'])->save();
        $withoutOcp = $this->user('no-ocp@example.test', 'Geen OCP');
        $withoutOcp->roles()->attach($pilotRole->id, ['created_at' => now()]);
        $withoutRole = $this->user('no-role@example.test', 'Geen Pilootrol');
        $withoutRole->teams()->attach($ocp->id, ['created_at' => now()]);
        $deployment = $this->deployment($manager, 'dispatching', false, 'PILOT-LIST-001');

        DeploymentPilotAssignment::query()->create([
            'deployment_id' => $deployment->id,
            'user_id' => $manualPilot->id,
            'user_name' => $manualPilot->name,
            'user_email' => $manualPilot->email,
            'assigned_by' => $manager->id,
            'assigned_by_name' => $manager->name,
            'assigned_by_email' => $manager->email,
            'reason' => 'Rechtstreeks gekoppeld.',
            'assigned_at' => now(),
        ]);
        AvailabilityStatus::query()->create([
            'user_id' => $manualPilot->id,
            'user_name' => $manualPilot->name,
            'user_email' => $manualPilot->email,
            'status' => 'en_route',
            'is_available' => false,
            'effective_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'deployment_id' => $deployment->id,
            'requested_by' => $manager->id,
            'requested_by_name' => $manager->name,
            'requested_by_email' => $manager->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Kom ter plaatse.',
            'sent_at' => now(),
        ]);
        $recipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $dispatchPilot->id,
            'user_name' => $dispatchPilot->name,
            'user_email' => $dispatchPilot->email,
            'response_status' => 'accepted',
            'notified_at' => now()->subMinute(),
            'responded_at' => now(),
        ]);

        $candidateResponse = $this->asWebClient($manager)
            ->getJson("/api/deployments/{$deployment->id}/pilot-candidates?search=kandidaat")
            ->assertOk();
        $this->assertSame([$candidate->id], collect($candidateResponse->json('data'))->pluck('id')->all());

        $participants = $this->asWebClient($manager)
            ->getJson("/api/deployments/{$deployment->id}/pilots")
            ->assertOk()
            ->json('data');
        $byUser = collect($participants)->keyBy('user_id');
        $this->assertSame('manual', $byUser->get($manualPilot->id)['source'] ?? null);
        $this->assertSame('en_route', $byUser->get($manualPilot->id)['user']['statuses'][0]['status'] ?? null);
        $this->assertSame('dispatch', $byUser->get($dispatchPilot->id)['source'] ?? null);
        $this->assertSame($recipient->id, $byUser->get($dispatchPilot->id)['id'] ?? null);
        $this->assertCount(2, $participants);
    }

    public function test_assignment_succeeds_with_warning_when_the_pilot_has_no_reachable_device(): void
    {
        Queue::fake();
        $manager = $this->manager('pilot-no-device-manager@example.test');
        [$pilotRole, $ocp] = $this->pilotStructure();
        $pilot = $this->pilot('pilot-no-device@example.test', $pilotRole, $ocp, 'Piloot Zonder Device');
        $deployment = $this->deployment($manager, 'active', false, 'PILOT-NO-DEVICE-001');

        $response = $this->asWebClient($manager)
            ->postJson("/api/deployments/{$deployment->id}/pilots", [
                'user_id' => $pilot->id,
                'reason' => 'Telefonisch aan de inzet toegevoegd.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $pilot->id)
            ->assertJsonPath('data.notification_queued_tokens', 0)
            ->assertJsonPath('meta.notification_queued_tokens', 0)
            ->assertJsonCount(1, 'meta.warnings');

        $assignmentId = (string) $response->json('data.id');
        $this->assertDatabaseHas('deployment_pilot_assignments', [
            'id' => $assignmentId,
            'deployment_id' => $deployment->id,
            'user_id' => $pilot->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deployments.pilot_manually_linked',
            'target_id' => $deployment->id,
        ]);
        $notificationAudit = AuditLog::query()
            ->where('action', 'deployments.pilot_manual_notification_queued')
            ->where('target_id', $deployment->id)
            ->sole();
        $this->assertSame(0, $notificationAudit->metadata['queued_devices'] ?? null);
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertDatabaseCount('dispatch_recipients', 0);
        $this->assertDatabaseCount('dispatch_push_outbox', 0);
        Queue::assertNotPushed(SendFcmNotification::class);
    }

    public function test_linking_an_already_on_scene_pilot_reconciles_dispatching_deployment(): void
    {
        Queue::fake();
        $manager = $this->manager('pilot-on-scene-manager@example.test');
        [$pilotRole, $ocp] = $this->pilotStructure();
        $pilot = $this->pilot('pilot-already-on-scene@example.test', $pilotRole, $ocp, 'Piloot Op Locatie');
        AvailabilityStatus::query()->create([
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'status' => 'on_scene',
            'is_available' => false,
            'effective_at' => now(),
        ]);
        $deployment = $this->deployment($manager, 'dispatching', false, 'PILOT-ON-SCENE');

        $this->asWebClient($manager)
            ->postJson("/api/deployments/{$deployment->id}/pilots", [
                'user_id' => $pilot->id,
                'reason' => 'De piloot staat al op de incidentlocatie.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $pilot->id)
            ->assertJsonPath('data.user.statuses.0.status', 'on_scene');

        $this->assertSame('in_progress', $deployment->refresh()->status);
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
        Queue::assertNotPushed(SendFcmNotification::class);
    }

    public function test_mutations_require_dispatch_management_and_reject_ineligible_or_closed_deployments(): void
    {
        Queue::fake();
        $manager = $this->manager('pilot-guard-manager@example.test');
        $viewer = $this->dispatchViewer('pilot-guard-viewer@example.test');
        [$pilotRole, $ocp] = $this->pilotStructure();
        $pilot = $this->pilot('pilot-guard-target@example.test', $pilotRole, $ocp, 'Doelpiloot');
        $active = $this->deployment($manager, 'active', false, 'PILOT-GUARD-ACTIVE');

        $this->asWebClient($viewer)
            ->getJson("/api/deployments/{$active->id}/pilots")
            ->assertOk();
        $this->asWebClient($viewer)
            ->getJson("/api/deployments/{$active->id}/pilot-candidates")
            ->assertForbidden();
        $this->asWebClient($viewer)
            ->postJson("/api/deployments/{$active->id}/pilots", [
                'user_id' => $pilot->id,
                'reason' => 'Onbevoegde poging.',
            ])
            ->assertForbidden();

        $this->asWebClient($manager)
            ->postJson("/api/deployments/{$active->id}/pilots", [
                'user_id' => $pilot->id,
                'reason' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);

        foreach ([
            $this->deployment($manager, 'draft', false, 'PILOT-GUARD-DRAFT'),
            $this->deployment($manager, 'active', true, 'PILOT-GUARD-TEST'),
            $this->deployment($manager, 'resolved', false, 'PILOT-GUARD-CLOSED'),
        ] as $deployment) {
            $this->asWebClient($manager)
                ->getJson("/api/deployments/{$deployment->id}/pilot-candidates")
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['details' => ['deployment_id']]]);
            $this->asWebClient($manager)
                ->postJson("/api/deployments/{$deployment->id}/pilots", [
                    'user_id' => $pilot->id,
                    'reason' => 'Mag niet worden gekoppeld.',
                ])
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['details' => ['deployment_id']]]);
        }

        $notPilot = $this->user('not-a-pilot@example.test', 'Geen Piloot');
        $this->asWebClient($manager)
            ->postJson("/api/deployments/{$active->id}/pilots", [
                'user_id' => $notPilot->id,
                'reason' => 'Ongeschikte kandidaat.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['user_id']]]);

        $this->assertDatabaseCount('deployment_pilot_assignments', 0);
        Queue::assertNothingPushed();
    }

    /** @return array{Role, Team} */
    private function pilotStructure(): array
    {
        $assignedPermission = $this->permission('deployments.assigned.view');
        $role = Role::query()->firstOrCreate(
            ['name' => 'operator-pilot'],
            [
                'display_name' => 'Operator / Pilot',
                'description' => 'Operationele piloot',
                'can_use_operator_app' => true,
                'can_use_admin_app' => false,
            ],
        );
        $role->permissions()->syncWithoutDetaching([$assignedPermission->id]);
        $team = Team::query()->firstOrCreate(
            ['code' => 'OCP'],
            [
                'name' => 'Operationeel Coördinatie Platform',
                'type' => 'base',
                'is_operational' => true,
            ],
        );

        return [$role, $team];
    }

    private function manager(string $email): User
    {
        return $this->withPermissions($email, [
            'deployments.view',
            'deployments.manage',
            'deployments.dispatch.view',
            'deployments.dispatch.manage',
        ], 'pilot-assignment-manager');
    }

    private function dispatchViewer(string $email): User
    {
        return $this->withPermissions($email, [
            'deployments.view',
            'deployments.dispatch.view',
        ], 'pilot-assignment-viewer');
    }

    /** @param list<string> $permissionNames */
    private function withPermissions(string $email, array $permissionNames, string $rolePrefix): User
    {
        $user = $this->user($email, 'Meldkamer');
        $role = Role::query()->create([
            'name' => $rolePrefix.'-'.str()->ulid(),
            'display_name' => 'Meldkamer',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        foreach ($permissionNames as $permissionName) {
            $role->permissions()->attach($this->permission($permissionName)->id);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function permission(string $name): Permission
    {
        return Permission::query()->firstOrCreate(
            ['name' => $name],
            [
                'category' => 'test',
                'display_name' => $name,
                'description' => $name,
            ],
        );
    }

    private function pilot(string $email, Role $role, Team $team, string $name): User
    {
        $pilot = $this->user($email, $name);
        $pilot->roles()->attach($role->id, ['created_at' => now()]);
        $pilot->teams()->attach($team->id, ['created_at' => now()]);

        return $pilot;
    }

    private function user(string $email, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'first_name' => (string) str($name)->before(' '),
            'last_name' => (string) str($name)->after(' '),
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => false,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function deployment(User $creator, string $status, bool $isTest, string $reference): Deployment
    {
        return Deployment::query()->create([
            'reference' => $reference,
            'title' => 'Inzet met handmatige pilootkoppeling',
            'description' => 'Test van het handmatig koppelen van piloten.',
            'priority' => 'normal',
            'status' => $status,
            'is_test' => $isTest,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
            'closed_at' => in_array($status, ['resolved', 'cancelled'], true) ? now() : null,
        ]);
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken(
            'Deployment pilot assignment test',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
