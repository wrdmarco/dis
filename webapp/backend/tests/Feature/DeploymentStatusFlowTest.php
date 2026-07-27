<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\AvailabilityWeekPattern;
use App\Models\Deployment;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DeploymentService;
use App\Services\DispatchService;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DeploymentStatusFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_flight_context_refresh_returns_the_actor_aware_deployment_payload(): void
    {
        $actor = $this->user('deployment-flight-context-refresh@example.test');
        $this->grantDeploymentManager($actor);
        $deployment = $this->deployment($actor, 'draft', 'FLOW-FLIGHT-CONTEXT');

        $this->asWebClient($actor)
            ->postJson("/api/deployments/{$deployment->id}/flight-context/refresh")
            ->assertOk()
            ->assertJsonPath('data.id', $deployment->id)
            ->assertJsonPath('data.drone_flight_context', null)
            ->assertJsonPath('data.deployment_request', null);
    }

    public function test_public_create_requires_deployment_request_preparation_and_internal_service_always_stores_draft(): void
    {
        Queue::fake();
        $actor = $this->user('deployment-create@example.test');
        $this->grantDeploymentManager($actor);

        $this->asWebClient($actor)
            ->postJson('/api/deployments', [
                'title' => 'Deployment met gekozen status',
                'description' => 'Een nieuw deployment moet altijd als concept beginnen.',
                'priority' => 'normal',
                'status' => 'active',
                'custom_fields' => [
                    'requesting_organization' => 'Testorganisatie',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $this->assertDatabaseCount('deployments', 0);

        $this->asWebClient($actor)
            ->postJson('/api/deployments', [
                'title' => 'Concept zonder aanvraagdossier',
                'description' => 'Publieke callers moeten eerst de uitvraag doorlopen.',
                'priority' => 'normal',
                'status' => 'draft',
                'custom_fields' => [
                    'requesting_organization' => 'Testorganisatie',
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'deployment_request_required');

        $this->assertDatabaseCount('deployments', 0);

        $deployment = app(DeploymentService::class)->create([
            'title' => 'Server-side concept',
            'description' => 'Ook interne aanroepers kunnen de beginstatus niet overschrijven.',
            'priority' => 'normal',
            'status' => 'resolved',
            'closed_at' => now(),
            'custom_fields' => [
                'requesting_organization' => 'Testorganisatie',
            ],
        ], $actor);

        $this->assertSame('draft', $deployment->status);
        $this->assertNull($deployment->closed_at);
        $this->assertDatabaseHas('deployment_status_history', [
            'deployment_id' => $deployment->id,
            'from_status' => null,
            'to_status' => 'draft',
        ]);
    }

    public function test_normal_updates_follow_the_operational_transition_matrix_and_allow_metadata_updates(): void
    {
        Queue::fake();
        $actor = $this->user('deployment-flow@example.test');
        $service = app(DeploymentService::class);

        $dispatching = $this->deployment($actor, 'dispatching', 'FLOW-DISPATCHING');
        $updated = $service->update($dispatching, ['title' => 'Bijgewerkte titel'], $actor);
        $this->assertSame('dispatching', $updated->status);
        $this->assertSame('Bijgewerkte titel', $updated->title);

        $inProgress = $service->update($updated->refresh(), [
            'status' => 'in_progress',
            'status_reason' => 'Iedereen is op locatie.',
        ], $actor);
        $this->assertSame('in_progress', $inProgress->status);

        $resolved = $service->close($inProgress->refresh(), $actor, 'Inzet afgerond.');
        $this->assertSame('resolved', $resolved->status);
        $this->assertNotNull($resolved->closed_at);

        $active = $this->deployment($actor, 'active', 'FLOW-ACTIVE-CANCEL');
        $this->assertSame(
            'cancelled',
            $service->cancel($active, $actor, 'Vooraankondiging ingetrokken.')->status,
        );
        $draft = $this->deployment($actor, 'draft', 'FLOW-DRAFT-CANCEL');
        $this->assertSame(
            'cancelled',
            $service->cancel($draft, $actor, 'Concept vervallen.')->status,
        );

        $staleDraft = $this->deployment($actor, 'draft', 'FLOW-STALE-DRAFT');
        $staleDraftSnapshot = Deployment::query()->findOrFail($staleDraft->id);
        $service->cancel($staleDraft, $actor, 'Gelijktijdige annulering.');
        $this->assertTransitionRejected(
            $service,
            $staleDraftSnapshot,
            ['status' => 'active', 'status_reason' => 'Verouderde activeringspoging.'],
            $actor,
        );

        foreach ([
            ['draft', 'dispatching', []],
            ['draft', 'resolved', []],
            ['active', 'in_progress', []],
            ['active', 'resolved', []],
            ['dispatching', 'cancelled', []],
            ['dispatching', 'resolved', []],
            ['in_progress', 'cancelled', []],
            ['resolved', 'draft', []],
            ['cancelled', 'active', []],
        ] as $index => [$from, $to, $extra]) {
            $deployment = $this->deployment($actor, $from, 'FLOW-REJECT-'.$index);
            $this->assertTransitionRejected(
                $service,
                $deployment,
                ['status' => $to, 'status_reason' => 'Niet toegestaan.'] + $extra,
                $actor,
            );
        }
    }

    public function test_manual_status_override_is_a_reasoned_system_administrator_correction_without_workflow_pushes(): void
    {
        Queue::fake();
        $manager = $this->user('deployment-manager@example.test');
        $this->grantDeploymentManager($manager);
        $systemAdministrator = $this->user('system-administrator@example.test');
        $this->grantDeploymentManager($systemAdministrator, systemAdministrator: true);

        $managerDeployment = $this->deployment($manager, 'resolved', 'FLOW-MANAGER');
        try {
            app(DeploymentService::class)->update($managerDeployment, [
                'status' => 'resolved',
                'manual_status_override' => true,
            ], $manager);
            $this->fail('Een niet-systeembeheerder kon rechtstreeks een handmatige correctie markeren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manual_status_override', $exception->errors());
        }

        $this->asWebClient($manager)
            ->patchJson('/api/deployments/'.$managerDeployment->id, [
                'status' => 'draft',
                'status_reason' => 'Onbevoegde correctiepoging.',
                'manual_status_override' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['manual_status_override']]]);
        $this->assertSame('resolved', $managerDeployment->refresh()->status);

        $deployment = $this->deployment($systemAdministrator, 'resolved', 'FLOW-SYSTEM-ADMIN');
        $deployment->forceFill([
            'report_pdf_path' => 'deployment-reports/'.$deployment->id.'/verouderd.pdf',
            'report_generated_at' => now(),
            'report_finalized_at' => now(),
            'report_generation_error' => 'Verouderde rapportstatus.',
        ])->save();
        $this->asWebClient($systemAdministrator)
            ->patchJson('/api/deployments/'.$deployment->id, [
                'status' => 'draft',
                'status_reason' => 'Zonder expliciete correctievlag.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $this->asWebClient($systemAdministrator)
            ->patchJson('/api/deployments/'.$deployment->id, [
                'status' => 'draft',
                'manual_status_override' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status_reason']]]);

        foreach (['draft', 'active', 'dispatching', 'in_progress', 'resolved', 'cancelled'] as $status) {
            $this->asWebClient($systemAdministrator)
                ->patchJson('/api/deployments/'.$deployment->id, [
                    'status' => $status,
                    'status_reason' => 'Handmatige correctie naar '.$status.'.',
                    'manual_status_override' => true,
                ])
                ->assertOk()
                ->assertJsonPath('data.status', $status);

            $deployment->refresh();
            $this->assertSame($status, $deployment->status);
            if ($status === 'draft') {
                $this->assertNull($deployment->report_pdf_path);
                $this->assertNull($deployment->report_generated_at);
                $this->assertNull($deployment->report_finalized_at);
                $this->assertNull($deployment->report_generation_error);
            }
            if (in_array($status, ['resolved', 'cancelled'], true)) {
                $this->assertNotNull($deployment->closed_at);
            } else {
                $this->assertNull($deployment->closed_at);
            }
        }

        $this->assertSame(0, $deployment->dispatchRequests()->count());
        Queue::assertNotPushed(SendFcmNotification::class);
        $this->assertDatabaseMissing('audit_logs', [
            'target_id' => $deployment->id,
            'action' => 'deployments.active_cancelled_notification_sent',
        ]);
        $this->assertSame(
            6,
            $deployment->statusHistory()
                ->whereNotNull('reason')
                ->where('reason', 'like', 'Handmatige correctie naar %')
                ->count(),
        );
    }

    public function test_automatic_in_progress_transition_only_runs_from_dispatching(): void
    {
        Queue::fake();
        $actor = $this->user('auto-flow-actor@example.test');
        $pilot = $this->user('auto-flow-pilot@example.test');

        $active = $this->deployment($actor, 'active', 'FLOW-AUTO-ACTIVE');
        $activeDispatch = $this->acceptedDispatch($active, $actor, $pilot);
        app(StatusService::class)->setStatus($pilot, 'on_scene', $pilot);
        $this->assertSame('active', $active->refresh()->status);

        $active->forceFill(['status' => 'dispatching'])->save();
        app(StatusService::class)->setStatus($pilot, 'on_scene', $pilot);
        $this->assertSame('in_progress', $active->refresh()->status);

        $responseDeployment = $this->deployment($actor, 'active', 'FLOW-RESPONSE-ACTIVE');
        $responseDispatch = $this->acceptedDispatch($responseDeployment, $actor, $pilot, 'pending');
        app(DispatchService::class)->respond($responseDispatch, $pilot, 'accepted', null);
        $this->assertSame('active', $responseDeployment->refresh()->status);

        $responseDeployment->forceFill(['status' => 'dispatching'])->save();
        $responseDispatch->recipients()->update([
            'response_status' => 'pending',
            'responded_at' => null,
        ]);
        app(DispatchService::class)->respond($responseDispatch->refresh(), $pilot, 'accepted', null);
        $this->assertSame('in_progress', $responseDeployment->refresh()->status);

        $this->assertSame('sent', $activeDispatch->refresh()->status);
    }

    public function test_terminal_deployment_resets_each_accepted_pilot_to_effective_scheduled_availability(): void
    {
        Queue::fake();
        $actor = $this->user('terminal-schedule-actor@example.test');
        $scheduledUnavailable = $this->user('terminal-schedule-unavailable@example.test');
        $defaultAvailable = $this->user('terminal-schedule-available@example.test');
        $scheduledUnavailable->forceFill(['push_enabled' => true])->save();
        $defaultAvailable->forceFill(['push_enabled' => true])->save();

        AvailabilityWeekPattern::query()->create([
            'user_id' => $scheduledUnavailable->id,
            'day_of_week' => now()->dayOfWeekIso,
            'day_part' => 'all_day',
            'is_available' => false,
            'created_by' => $actor->id,
        ]);

        $deployment = $this->deployment($actor, 'in_progress', 'FLOW-TERMINAL-SCHEDULE');
        $dispatch = $this->acceptedDispatch($deployment, $actor, $scheduledUnavailable);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $defaultAvailable->id,
            'user_name' => $defaultAvailable->name,
            'user_email' => $defaultAvailable->email,
            'response_status' => 'accepted',
            'responded_at' => now(),
            'notified_at' => now(),
        ]);
        app(StatusService::class)->setStatus($scheduledUnavailable, 'en_route', $scheduledUnavailable);
        app(StatusService::class)->setStatus($defaultAvailable, 'en_route', $defaultAvailable);

        $resolved = app(DeploymentService::class)->close($deployment, $actor, 'Inzet afgerond.');

        $this->assertSame('resolved', $resolved->status);
        $this->assertDatabaseHas('availability_statuses', [
            'user_id' => $scheduledUnavailable->id,
            'status' => 'unavailable',
            'is_available' => false,
            'is_system_applied' => true,
        ]);
        $this->assertDatabaseHas('availability_statuses', [
            'user_id' => $defaultAvailable->id,
            'status' => 'available',
            'is_available' => true,
            'is_system_applied' => true,
        ]);
    }

    private function assertTransitionRejected(
        DeploymentService $service,
        Deployment $deployment,
        array $payload,
        User $actor,
    ): void {
        $expectedStatus = (string) Deployment::query()
            ->whereKey($deployment->getKey())
            ->value('status');

        try {
            $service->update($deployment, $payload, $actor);
            $this->fail('De ongeldige deploymentstatusovergang werd niet geweigerd.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame($expectedStatus, $deployment->refresh()->status);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Testgebruiker',
            'first_name' => 'Test',
            'last_name' => 'Gebruiker',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function grantDeploymentManager(User $user, bool $systemAdministrator = false): Role
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'deployments.manage'],
            [
                'category' => 'test',
                'display_name' => 'Deploymenten beheren',
                'description' => 'Deploymenten beheren',
            ],
        );
        $role = Role::query()->create([
            'name' => $systemAdministrator ? Role::SYSTEM_ADMINISTRATOR : 'deployment-manager-'.str()->ulid(),
            'display_name' => $systemAdministrator ? 'System Administrator' : 'Deploymentmanager',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $role;
    }

    private function deployment(User $creator, string $status, string $reference): Deployment
    {
        return Deployment::query()->create([
            'reference' => $reference,
            'title' => 'Testdeployment',
            'description' => 'Test van de deploymentstatusflow.',
            'priority' => 'normal',
            'status' => $status,
            'is_test' => false,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
            'closed_at' => in_array($status, ['resolved', 'cancelled'], true) ? now() : null,
        ]);
    }

    private function acceptedDispatch(
        Deployment $deployment,
        User $actor,
        User $pilot,
        string $responseStatus = 'accepted',
    ): DispatchRequest {
        $dispatch = DispatchRequest::query()->create([
            'deployment_id' => $deployment->id,
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
            'requested_by_email' => $actor->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Kom ter plaatse.',
            'sent_at' => now(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'response_status' => $responseStatus,
            'responded_at' => $responseStatus === 'pending' ? null : now(),
            'notified_at' => now(),
        ]);

        return $dispatch;
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken('Deployment status flow test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
