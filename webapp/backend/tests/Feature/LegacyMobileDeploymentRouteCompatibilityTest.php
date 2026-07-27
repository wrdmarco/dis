<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Permission;
use App\Models\PilotDeploymentReport;
use App\Models\Role;
use App\Models\User;
use App\Support\MobileApiPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class LegacyMobileDeploymentRouteCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_cutover_operator_can_read_and_respond_to_a_preannouncement(): void
    {
        $operator = $this->operator('legacy-preannouncement@example.test');
        $creator = $this->user('legacy-preannouncement-creator@example.test');
        $deployment = $this->deployment($creator, 'LEGACY-PRE-001');
        $dispatch = $this->dispatch($deployment, $creator, $operator, 'draft');
        $token = $this->operatorToken($operator);

        $list = $this->withToken($token)
            ->getJson('/api/incidents?active_alarms=true')
            ->assertOk();
        $listed = collect($list->json('data'))->firstWhere('id', $deployment->id);
        $this->assertSame('draft', $listed['active_dispatch']['status'] ?? null);

        $this->withToken($token)
            ->getJson('/api/incidents/'.$deployment->id)
            ->assertOk()
            ->assertJsonPath('data.id', $deployment->id)
            ->assertJsonPath('data.reference', 'Vooraankondiging')
            ->assertJsonPath('data.active_dispatch.id', $dispatch->id)
            ->assertJsonPath('data.active_dispatch.status', 'draft');

        $this->withToken($token)
            ->getJson('/api/incidents/'.$deployment->id.'/timeline')
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/dispatches/'.$dispatch->id.'/respond', ['response' => 'accepted'])
            ->assertNoContent();

        $this->withToken($token)
            ->getJson('/api/status/me')
            ->assertOk();

        $this->assertDatabaseHas('dispatch_recipients', [
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $operator->id,
            'response_status' => 'accepted',
        ]);
    }

    public function test_pre_cutover_operator_location_paths_return_dual_domain_identifiers(): void
    {
        $operator = $this->operator('legacy-location@example.test');
        $creator = $this->user('legacy-location-creator@example.test');
        $deployment = $this->deployment($creator, 'LEGACY-LOC-001');
        $this->dispatch($deployment, $creator, $operator, 'sent', 'accepted');
        $token = $this->operatorToken($operator);

        $this->withToken($token)
            ->postJson('/api/incidents/'.$deployment->id.'/location/consent')
            ->assertCreated()
            ->assertJsonPath('data.deployment_id', $deployment->id)
            ->assertJsonPath('data.incident_id', $deployment->id)
            ->assertJsonPath('data.is_active', true);

        $this->withToken($token)
            ->postJson('/api/incidents/'.$deployment->id.'/location', [
                'latitude' => 52.087,
                'longitude' => 5.121,
                'accuracy_meters' => 8,
            ])
            ->assertNoContent();

        $this->withToken($token)
            ->getJson('/api/incidents/'.$deployment->id.'/live-locations')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $operator->id)
            ->assertJsonPath('data.0.sharing_status', 'shared');

        $this->withToken($token)
            ->deleteJson('/api/incidents/'.$deployment->id.'/location/consent')
            ->assertNoContent();

        $this->withToken($token)
            ->postJson('/api/incidents/'.$deployment->id.'/location/decline', [
                'reason' => 'Niet meer nodig',
            ])
            ->assertOk()
            ->assertJsonPath('data.deployment_id', $deployment->id)
            ->assertJsonPath('data.incident_id', $deployment->id)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_pre_cutover_operator_report_download_paths_are_preserved(): void
    {
        $operator = $this->operator('legacy-report@example.test');
        $creator = $this->user('legacy-report-creator@example.test');
        $deployment = $this->deployment($creator, 'LEGACY-REPORT-001');
        $this->dispatch($deployment, $creator, $operator, 'sent', 'accepted');
        $token = $this->operatorToken($operator);

        foreach (['report', 'report.pdf'] as $suffix) {
            $this->withToken($token)
                ->getJson('/api/incidents/'.$deployment->id.'/'.$suffix)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'deployment_not_closed');
        }

        $unassigned = $this->deployment($creator, 'LEGACY-REPORT-UNASSIGNED');
        $this->withToken($token)
            ->getJson('/api/incidents/'.$unassigned->id.'/report')
            ->assertForbidden();
    }

    public function test_legacy_payload_aliases_remain_additive_for_intake_and_pilot_reports(): void
    {
        $operator = $this->operator('legacy-payload@example.test');
        $creator = $this->user('legacy-payload-creator@example.test');
        $deployment = $this->deployment($creator, 'LEGACY-PAYLOAD-001');
        $report = PilotDeploymentReport::query()->create([
            'deployment_id' => $deployment->id,
            'user_id' => $operator->id,
            'user_name' => $operator->name,
            'user_email' => $operator->email,
            'status' => 'draft',
        ]);
        $operatorAccessToken = $operator
            ->createToken('Legacy Android', ['*', 'client:operator'], now()->addHour())
            ->accessToken;
        $operator->withAccessToken($operatorAccessToken);

        $deploymentPayload = MobileApiPayload::deployment($deployment, $operator);
        $reportPayload = MobileApiPayload::pilotDeploymentReport($report);

        $this->assertArrayHasKey('deployment_request', $deploymentPayload);
        $this->assertArrayHasKey('intake', $deploymentPayload);
        $this->assertSame($deploymentPayload['deployment_request'], $deploymentPayload['intake']);
        $this->assertArrayHasKey('intake_dossier_id', $deploymentPayload);
        $this->assertSame($deploymentPayload['deployment_request_id'], $deploymentPayload['intake_dossier_id']);
        $this->assertSame($deployment->id, $reportPayload['deployment_id']);
        $this->assertSame($deployment->id, $reportPayload['incident_id']);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Legacy Mobile User',
            'first_name' => 'Legacy',
            'last_name' => 'Mobile',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function operator(string $email): User
    {
        $user = $this->user($email);
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'deployments.assigned.view'],
            [
                'category' => 'test',
                'display_name' => 'Assigned deployments',
                'description' => 'Assigned deployment access',
            ],
        );
        $reportPermission = Permission::query()->firstOrCreate(
            ['name' => 'deployments.view'],
            [
                'category' => 'test',
                'display_name' => 'View deployments',
                'description' => 'Deployment report access',
            ],
        );
        $role = Role::query()->create([
            'name' => 'legacy-mobile-'.strtolower((string) str()->ulid()),
            'display_name' => 'Legacy mobile operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $role->permissions()->attach([$permission->id, $reportPermission->id]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function deployment(User $creator, string $reference): Deployment
    {
        return Deployment::query()->create([
            'reference' => $reference,
            'title' => 'Compatibiliteitsinzet',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => false,
            'location_label' => 'Utrecht',
            'latitude' => 52.0907,
            'longitude' => 5.1214,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
        ]);
    }

    private function dispatch(
        Deployment $deployment,
        User $creator,
        User $recipient,
        string $status,
        string $response = 'pending',
    ): DispatchRequest {
        $dispatch = DispatchRequest::query()->create([
            'deployment_id' => $deployment->id,
            'requested_by' => $creator->id,
            'requested_by_name' => $creator->name,
            'requested_by_email' => $creator->email,
            'status' => $status,
            'priority' => 'normal',
            'message' => 'Testmelding',
            'sent_at' => $status === 'draft' ? null : now(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $recipient->id,
            'user_name' => $recipient->name,
            'user_email' => $recipient->email,
            'response_status' => $response,
            'notified_at' => now(),
            'responded_at' => $response === 'pending' ? null : now(),
        ]);

        return $dispatch;
    }

    private function operatorToken(User $operator): string
    {
        return $operator
            ->createToken('Legacy Android', ['*', 'client:operator'], now()->addHour())
            ->plainTextToken;
    }
}
