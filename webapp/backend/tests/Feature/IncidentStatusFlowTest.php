<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DispatchService;
use App\Services\IncidentService;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class IncidentStatusFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_rejects_a_non_draft_client_status_and_the_service_always_stores_draft(): void
    {
        Queue::fake();
        $actor = $this->user('incident-create@example.test');
        $this->grantIncidentManager($actor);

        $this->asWebClient($actor)
            ->postJson('/api/incidents', [
                'title' => 'Incident met gekozen status',
                'description' => 'Een nieuw incident moet altijd als concept beginnen.',
                'priority' => 'normal',
                'status' => 'active',
                'custom_fields' => [
                    'requesting_organization' => 'Testorganisatie',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $this->assertDatabaseCount('incidents', 0);

        $this->asWebClient($actor)
            ->postJson('/api/incidents', [
                'title' => 'Compatibel concept',
                'description' => 'Een ouder formulier mag de vaste conceptstatus nog meesturen.',
                'priority' => 'normal',
                'status' => 'draft',
                'custom_fields' => [
                    'requesting_organization' => 'Testorganisatie',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $incident = app(IncidentService::class)->create([
            'title' => 'Server-side concept',
            'description' => 'Ook interne aanroepers kunnen de beginstatus niet overschrijven.',
            'priority' => 'normal',
            'status' => 'resolved',
            'closed_at' => now(),
            'custom_fields' => [
                'requesting_organization' => 'Testorganisatie',
            ],
        ], $actor);

        $this->assertSame('draft', $incident->status);
        $this->assertNull($incident->closed_at);
        $this->assertDatabaseHas('incident_status_history', [
            'incident_id' => $incident->id,
            'from_status' => null,
            'to_status' => 'draft',
        ]);
    }

    public function test_normal_updates_follow_the_operational_transition_matrix_and_allow_metadata_updates(): void
    {
        Queue::fake();
        $actor = $this->user('incident-flow@example.test');
        $service = app(IncidentService::class);

        $dispatching = $this->incident($actor, 'dispatching', 'FLOW-DISPATCHING');
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

        $active = $this->incident($actor, 'active', 'FLOW-ACTIVE-CANCEL');
        $this->assertSame(
            'cancelled',
            $service->cancel($active, $actor, 'Vooraankondiging ingetrokken.')->status,
        );
        $draft = $this->incident($actor, 'draft', 'FLOW-DRAFT-CANCEL');
        $this->assertSame(
            'cancelled',
            $service->cancel($draft, $actor, 'Concept vervallen.')->status,
        );

        $staleDraft = $this->incident($actor, 'draft', 'FLOW-STALE-DRAFT');
        $staleDraftSnapshot = Incident::query()->findOrFail($staleDraft->id);
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
            $incident = $this->incident($actor, $from, 'FLOW-REJECT-'.$index);
            $this->assertTransitionRejected(
                $service,
                $incident,
                ['status' => $to, 'status_reason' => 'Niet toegestaan.'] + $extra,
                $actor,
            );
        }
    }

    public function test_manual_status_override_is_a_reasoned_system_administrator_correction_without_workflow_pushes(): void
    {
        Queue::fake();
        $manager = $this->user('incident-manager@example.test');
        $this->grantIncidentManager($manager);
        $systemAdministrator = $this->user('system-administrator@example.test');
        $this->grantIncidentManager($systemAdministrator, systemAdministrator: true);

        $managerIncident = $this->incident($manager, 'resolved', 'FLOW-MANAGER');
        try {
            app(IncidentService::class)->update($managerIncident, [
                'status' => 'resolved',
                'manual_status_override' => true,
            ], $manager);
            $this->fail('Een niet-systeembeheerder kon rechtstreeks een handmatige correctie markeren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manual_status_override', $exception->errors());
        }

        $this->asWebClient($manager)
            ->patchJson('/api/incidents/'.$managerIncident->id, [
                'status' => 'draft',
                'status_reason' => 'Onbevoegde correctiepoging.',
                'manual_status_override' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['manual_status_override']]]);
        $this->assertSame('resolved', $managerIncident->refresh()->status);

        $incident = $this->incident($systemAdministrator, 'resolved', 'FLOW-SYSTEM-ADMIN');
        $incident->forceFill([
            'report_pdf_path' => 'incident-reports/'.$incident->id.'/verouderd.pdf',
            'report_generated_at' => now(),
            'report_finalized_at' => now(),
            'report_generation_error' => 'Verouderde rapportstatus.',
        ])->save();
        $this->asWebClient($systemAdministrator)
            ->patchJson('/api/incidents/'.$incident->id, [
                'status' => 'draft',
                'status_reason' => 'Zonder expliciete correctievlag.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $this->asWebClient($systemAdministrator)
            ->patchJson('/api/incidents/'.$incident->id, [
                'status' => 'draft',
                'manual_status_override' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status_reason']]]);

        foreach (['draft', 'active', 'dispatching', 'in_progress', 'resolved', 'cancelled'] as $status) {
            $this->asWebClient($systemAdministrator)
                ->patchJson('/api/incidents/'.$incident->id, [
                    'status' => $status,
                    'status_reason' => 'Handmatige correctie naar '.$status.'.',
                    'manual_status_override' => true,
                ])
                ->assertOk()
                ->assertJsonPath('data.status', $status);

            $incident->refresh();
            $this->assertSame($status, $incident->status);
            if ($status === 'draft') {
                $this->assertNull($incident->report_pdf_path);
                $this->assertNull($incident->report_generated_at);
                $this->assertNull($incident->report_finalized_at);
                $this->assertNull($incident->report_generation_error);
            }
            if (in_array($status, ['resolved', 'cancelled'], true)) {
                $this->assertNotNull($incident->closed_at);
            } else {
                $this->assertNull($incident->closed_at);
            }
        }

        $this->assertSame(0, $incident->dispatchRequests()->count());
        Queue::assertNotPushed(SendFcmNotification::class);
        $this->assertDatabaseMissing('audit_logs', [
            'target_id' => $incident->id,
            'action' => 'incidents.active_cancelled_notification_sent',
        ]);
        $this->assertSame(
            6,
            $incident->statusHistory()
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

        $active = $this->incident($actor, 'active', 'FLOW-AUTO-ACTIVE');
        $activeDispatch = $this->acceptedDispatch($active, $actor, $pilot);
        app(StatusService::class)->setStatus($pilot, 'on_scene', $pilot);
        $this->assertSame('active', $active->refresh()->status);

        $active->forceFill(['status' => 'dispatching'])->save();
        app(StatusService::class)->setStatus($pilot, 'on_scene', $pilot);
        $this->assertSame('in_progress', $active->refresh()->status);

        $responseIncident = $this->incident($actor, 'active', 'FLOW-RESPONSE-ACTIVE');
        $responseDispatch = $this->acceptedDispatch($responseIncident, $actor, $pilot, 'pending');
        app(DispatchService::class)->respond($responseDispatch, $pilot, 'accepted', null);
        $this->assertSame('active', $responseIncident->refresh()->status);

        $responseIncident->forceFill(['status' => 'dispatching'])->save();
        $responseDispatch->recipients()->update([
            'response_status' => 'pending',
            'responded_at' => null,
        ]);
        app(DispatchService::class)->respond($responseDispatch->refresh(), $pilot, 'accepted', null);
        $this->assertSame('in_progress', $responseIncident->refresh()->status);

        $this->assertSame('sent', $activeDispatch->refresh()->status);
    }

    private function assertTransitionRejected(
        IncidentService $service,
        Incident $incident,
        array $payload,
        User $actor,
    ): void {
        $expectedStatus = (string) Incident::query()
            ->whereKey($incident->getKey())
            ->value('status');

        try {
            $service->update($incident, $payload, $actor);
            $this->fail('De ongeldige incidentstatusovergang werd niet geweigerd.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame($expectedStatus, $incident->refresh()->status);
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

    private function grantIncidentManager(User $user, bool $systemAdministrator = false): Role
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'incidents.manage'],
            [
                'category' => 'test',
                'display_name' => 'Incidenten beheren',
                'description' => 'Incidenten beheren',
            ],
        );
        $role = Role::query()->create([
            'name' => $systemAdministrator ? Role::SYSTEM_ADMINISTRATOR : 'incident-manager-'.str()->ulid(),
            'display_name' => $systemAdministrator ? 'System Administrator' : 'Incidentmanager',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $role;
    }

    private function incident(User $creator, string $status, string $reference): Incident
    {
        return Incident::query()->create([
            'reference' => $reference,
            'title' => 'Testincident',
            'description' => 'Test van de incidentstatusflow.',
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
        Incident $incident,
        User $actor,
        User $pilot,
        string $responseStatus = 'accepted',
    ): DispatchRequest {
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
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
        $token = $user->createToken('Incident status flow test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
