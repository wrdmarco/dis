<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class IamAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_only_sees_deployments_assigned_to_the_current_user(): void
    {
        $operator = $this->user('operator@example.test');
        $otherOperator = $this->user('other@example.test');
        $creator = $this->user('creator@example.test');
        $this->grant($operator, ['deployments.assigned.view'], operator: true, admin: false);

        $assigned = $this->deployment($creator, 'ASSIGNED-001');
        $unassigned = $this->deployment($creator, 'UNASSIGNED-001');
        $this->dispatch($assigned, $creator, $operator, 'sent');
        $this->dispatch($unassigned, $creator, $otherOperator, 'sent');

        $response = $this->asClient($operator, 'client:operator')->getJson('/api/deployments');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($unassigned->id));

        $this->asClient($operator, 'client:operator')
            ->getJson('/api/deployments/'.$unassigned->id)
            ->assertForbidden();
    }

    public function test_assigned_only_permission_cannot_list_deployments_or_dispatches_from_web_clients(): void
    {
        $actor = $this->user('dual-client-assigned@example.test');
        $creator = $this->user('dual-client-creator@example.test');
        $this->grant($actor, ['deployments.assigned.view'], operator: true, admin: true);

        $deployment = $this->deployment($creator, 'WEB-ASSIGNED-001');
        $this->dispatch($deployment, $creator, $actor, 'sent');

        $this->asClient($actor, 'client:web')
            ->getJson('/api/deployments')
            ->assertForbidden();

        $this->asClient($actor, 'client:web')
            ->getJson('/api/dispatches')
            ->assertForbidden();
    }

    public function test_dual_client_assigned_only_permission_remains_scoped_for_operator_client(): void
    {
        $operator = $this->user('dual-client-operator@example.test');
        $otherOperator = $this->user('dual-client-other@example.test');
        $creator = $this->user('dual-client-scope-creator@example.test');
        $this->grant($operator, ['deployments.assigned.view'], operator: true, admin: true);

        $assignedDeployment = $this->deployment($creator, 'DUAL-ASSIGNED-001');
        $unassignedDeployment = $this->deployment($creator, 'DUAL-UNASSIGNED-001');
        $assignedDispatch = $this->dispatch($assignedDeployment, $creator, $operator, 'sent');
        $unassignedDispatch = $this->dispatch($unassignedDeployment, $creator, $otherOperator, 'sent');

        $deploymentResponse = $this->asClient($operator, 'client:operator')->getJson('/api/deployments');
        $deploymentResponse->assertOk();
        $deploymentIds = collect($deploymentResponse->json('data'))->pluck('id');
        $this->assertTrue($deploymentIds->contains($assignedDeployment->id));
        $this->assertFalse($deploymentIds->contains($unassignedDeployment->id));

        $dispatchResponse = $this->asClient($operator, 'client:operator')->getJson('/api/dispatches');
        $dispatchResponse->assertOk();
        $dispatchIds = collect($dispatchResponse->json('data'))->pluck('id');
        $this->assertTrue($dispatchIds->contains($assignedDispatch->id));
        $this->assertFalse($dispatchIds->contains($unassignedDispatch->id));
    }

    public function test_broad_deployment_permissions_keep_full_web_collection_access(): void
    {
        $viewer = $this->user('broad-web-viewer@example.test');
        $creator = $this->user('broad-web-creator@example.test');
        $this->grant($viewer, ['deployments.view', 'deployments.dispatch.view'], operator: false, admin: true);

        $firstDeployment = $this->deployment($creator, 'BROAD-001');
        $secondDeployment = $this->deployment($creator, 'BROAD-002');
        $firstDispatch = $this->dispatch($firstDeployment, $creator, $creator, 'sent');
        $secondDispatch = $this->dispatch($secondDeployment, $creator, $creator, 'sent');

        $deploymentResponse = $this->asClient($viewer, 'client:web')->getJson('/api/deployments');
        $deploymentResponse->assertOk();
        $deploymentIds = collect($deploymentResponse->json('data'))->pluck('id');
        $this->assertTrue($deploymentIds->contains($firstDeployment->id));
        $this->assertTrue($deploymentIds->contains($secondDeployment->id));

        $dispatchResponse = $this->asClient($viewer, 'client:web')->getJson('/api/dispatches');
        $dispatchResponse->assertOk();
        $dispatchIds = collect($dispatchResponse->json('data'))->pluck('id');
        $this->assertTrue($dispatchIds->contains($firstDispatch->id));
        $this->assertTrue($dispatchIds->contains($secondDispatch->id));
    }

    public function test_deployment_manage_implies_web_view_without_granting_operator_unscoped_access(): void
    {
        $manager = $this->user('manage-only-dual-client@example.test');
        $creator = $this->user('manage-only-creator@example.test');
        $this->grant($manager, ['deployments.manage'], operator: true, admin: true);
        $deployment = $this->deployment($creator, 'MANAGE-ONLY-001');

        $webList = $this->asClient($manager, 'client:web')
            ->getJson('/api/deployments')
            ->assertOk();
        $this->assertContains($deployment->id, collect($webList->json('data'))->pluck('id')->all());
        $this->asClient($manager, 'client:web')
            ->getJson('/api/deployments/'.$deployment->id)
            ->assertOk()
            ->assertJsonPath('data.id', $deployment->id);
        $this->asClient($manager, 'client:web')
            ->getJson('/api/deployments/'.$deployment->id.'/timeline')
            ->assertOk();

        Auth::forgetGuards();
        $this->asClient($manager, 'client:operator')
            ->getJson('/api/deployments')
            ->assertForbidden();
        $this->asClient($manager, 'client:operator')
            ->getJson('/api/deployments/'.$deployment->id)
            ->assertForbidden();
        $this->asClient($manager, 'client:operator')
            ->getJson('/api/deployments/'.$deployment->id.'/timeline')
            ->assertForbidden();
        $this->asClient($manager, 'client:operator')
            ->getJson('/api/deployments/'.$deployment->id.'/live-locations')
            ->assertForbidden();
        $this->asClient($manager, 'client:operator')
            ->getJson('/api/reports/deployments')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_preannouncement_hides_operational_details(): void
    {
        $operator = $this->user('operator@example.test');
        $creator = $this->user('creator@example.test');
        $this->grant($operator, ['deployments.assigned.view'], operator: true, admin: false);

        $deployment = $this->deployment($creator, 'PRE-001', [
            'reporter_name' => 'Geheime melder',
            'reporter_phone' => '+31612345678',
            'location_label' => "McDonald's, Botnische golf 1, 3446 CN, Woerden, Utrecht, Nederland",
            'latitude' => 52.3731,
            'longitude' => 4.8922,
            'custom_fields' => ['secret' => 'niet tonen'],
        ]);
        $dispatch = $this->dispatch($deployment, $creator, $operator, 'draft');
        $dispatch->update([
            'priority' => 'critical',
            'message' => implode(' - ', [
                $deployment->reference,
                $deployment->title,
                $deployment->location_label,
            ]),
        ]);

        $response = $this->asClient($operator, 'client:operator')->getJson('/api/deployments/'.$deployment->id);

        $response->assertOk()
            ->assertJsonPath('data.reference', 'Vooraankondiging')
            ->assertJsonPath('data.reporter_name', null)
            ->assertJsonPath('data.reporter_phone', null)
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null)
            ->assertJsonPath('data.title', 'Beschikbaar voor een mogelijke inzet in Woerden?')
            ->assertJsonPath('data.location_label', 'Woerden')
            ->assertJsonPath('data.custom_fields', []);
        $this->assertStringContainsString('"custom_fields":{}', $response->getContent());
        $this->assertStringNotContainsString('Botnische golf 1', (string) $response->json('data.location_label'));

        $dispatchResponse = $this->asClient($operator, 'client:operator')
            ->getJson('/api/dispatches/'.$dispatch->id)
            ->assertOk()
            ->assertJsonPath('data.message', 'Vooraankondiging')
            ->assertJsonPath('data.priority', 'normal')
            ->assertJsonPath('data.deployment.title', 'Beschikbaar voor een mogelijke inzet in Woerden?')
            ->assertJsonPath('data.deployment.location_label', 'Woerden');
        $this->assertStringNotContainsString('Botnische golf 1', $dispatchResponse->getContent());
        $this->assertStringNotContainsString('Testdeployment', (string) $dispatchResponse->json('data.message'));

        $list = $this->asClient($operator, 'client:operator')
            ->getJson('/api/dispatches')
            ->assertOk()
            ->json('data');
        $listedDispatch = collect($list)->firstWhere('id', $dispatch->id);
        $this->assertSame('Vooraankondiging', $listedDispatch['message'] ?? null);
        $this->assertSame('normal', $listedDispatch['priority'] ?? null);
        $this->assertStringNotContainsString('Botnische golf 1', json_encode($listedDispatch, JSON_THROW_ON_ERROR));
    }

    public function test_operator_timeline_does_not_expose_other_recipients(): void
    {
        $operator = $this->user('operator@example.test', 'Eigen Operator');
        $otherOperator = $this->user('other@example.test', 'Andere Operator');
        $creator = $this->user('creator@example.test');
        $this->grant($operator, ['deployments.assigned.view'], operator: true, admin: false);

        $deployment = $this->deployment($creator, 'TIMELINE-001');
        $dispatch = $this->dispatch($deployment, $creator, $operator, 'sent');
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $otherOperator->id,
            'user_name' => $otherOperator->name,
            'user_email' => $otherOperator->email,
            'response_status' => 'accepted',
            'responded_at' => now(),
        ]);

        $response = $this->asClient($operator, 'client:operator')->getJson('/api/deployments/'.$deployment->id.'/timeline');

        $response->assertOk();
        $labels = collect($response->json('data'))->pluck('label')->implode(' ');
        $this->assertStringContainsString('Eigen Operator', $labels);
        $this->assertStringNotContainsString('Andere Operator', $labels);
    }

    public function test_role_manager_cannot_grant_a_permission_they_do_not_hold(): void
    {
        $actor = $this->user('role-manager@example.test');
        $this->grant($actor, ['roles.manage'], operator: false, admin: true);
        $forbiddenPermission = $this->permission('users.manage');

        $this->asClient($actor, 'client:web')->postJson('/api/admin/roles', [
            'name' => 'escalated-role',
            'display_name' => 'Escalated role',
            'description' => null,
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
            'permission_ids' => [$forbiddenPermission->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'escalated-role']);
    }

    public function test_role_manager_cannot_strip_a_permission_they_do_not_hold(): void
    {
        $actor = $this->user('limited-role-manager@example.test');
        $this->grant($actor, ['roles.manage'], operator: false, admin: true);
        $forbiddenPermission = $this->permission('users.manage');
        $protectedRole = Role::query()->create([
            'name' => 'protected-higher-role',
            'display_name' => 'Protected higher role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $protectedRole->permissions()->attach($forbiddenPermission->id);

        $this->asClient($actor, 'client:web')
            ->patchJson('/api/admin/roles/'.$protectedRole->id, [
                'display_name' => 'Stripped role',
                'permission_ids' => [],
            ])
            ->assertForbidden();

        $this->assertTrue($protectedRole->permissions()->whereKey($forbiddenPermission->id)->exists());
        $this->assertSame('Protected higher role', $protectedRole->refresh()->display_name);
    }

    public function test_user_manager_cannot_remove_a_role_with_permissions_they_do_not_hold(): void
    {
        $actor = $this->user('limited-user-role-manager@example.test');
        $target = $this->user('higher-role-target@example.test');
        $this->grant($actor, ['users.manage', 'roles.manage'], operator: false, admin: true);
        $higherPermission = $this->permission('settings.manage');
        $higherRole = Role::query()->create([
            'name' => 'higher-target-role',
            'display_name' => 'Higher target role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $higherRole->permissions()->attach($higherPermission->id);
        $target->roles()->attach($higherRole->id, ['created_at' => now()]);

        $this->asClient($actor, 'client:web')
            ->deleteJson('/api/users/'.$target->id.'/roles/'.$higherRole->id)
            ->assertForbidden();

        $this->assertTrue($target->roles()->whereKey($higherRole->id)->exists());
    }

    public function test_non_system_administrator_cannot_modify_system_administrator(): void
    {
        $actor = $this->user('manager@example.test');
        $target = $this->user('root@example.test');
        $this->grant($actor, ['users.manage'], operator: false, admin: true);
        $systemRole = Role::query()->create([
            'name' => Role::SYSTEM_ADMINISTRATOR,
            'display_name' => 'System Administrator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
        ]);
        $target->roles()->attach($systemRole->id, ['created_at' => now()]);

        $this->asClient($actor, 'client:web')
            ->patchJson('/api/users/'.$target->id, ['first_name' => 'Gewijzigd'])
            ->assertForbidden();

        $this->assertNotSame('Gewijzigd', $target->refresh()->first_name);
    }

    public function test_user_manager_cannot_delete_without_dedicated_permission(): void
    {
        $actor = $this->user('manager@example.test');
        $target = $this->user('target@example.test');
        $this->grant($actor, ['users.manage'], operator: false, admin: true);

        $this->asClient($actor, 'client:web')
            ->deleteJson('/api/users/'.$target->id)
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_role_manager_cannot_delete_without_dedicated_permission(): void
    {
        $actor = $this->user('role-manager-delete@example.test');
        $this->grant($actor, ['roles.manage'], operator: false, admin: true);
        $role = Role::query()->create([
            'name' => 'deletable-role',
            'display_name' => 'Deletable role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);

        $this->asClient($actor, 'client:web')
            ->deleteJson('/api/admin/roles/'.$role->id)
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_dispatch_recipient_still_needs_an_operational_permission_to_respond(): void
    {
        $operator = $this->user('recipient-without-permission@example.test');
        $creator = $this->user('dispatch-creator@example.test');
        $this->grant($operator, [], operator: true, admin: false);
        $dispatch = $this->dispatch($this->deployment($creator, 'RESPOND-001'), $creator, $operator, 'sent');

        $this->asClient($operator, 'client:operator')
            ->postJson('/api/dispatches/'.$dispatch->id.'/respond', ['response' => 'accepted'])
            ->assertForbidden();

        $this->assertDatabaseHas('dispatch_recipients', [
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $operator->id,
            'response_status' => 'pending',
        ]);
    }

    private function user(string $email, string $name = 'Test User'): User
    {
        return User::query()->create([
            'name' => $name,
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
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
            'name' => 'role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Test role',
            'can_use_operator_app' => $operator,
            'can_use_admin_app' => $admin,
        ]);
        $permissions = collect($permissionNames)->map(fn (string $name): Permission => $this->permission($name));
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $role;
    }

    private function permission(string $name): Permission
    {
        return Permission::query()->firstOrCreate(
            ['name' => $name],
            [
                'category' => 'test',
                'display_name' => $name,
                'description' => 'Test permission',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function deployment(User $creator, string $reference, array $overrides = []): Deployment
    {
        return Deployment::query()->create($overrides + [
            'reference' => $reference,
            'title' => 'Testdeployment',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => false,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
        ]);
    }

    private function dispatch(Deployment $deployment, User $creator, User $recipient, string $status): DispatchRequest
    {
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
            'response_status' => 'pending',
            'notified_at' => now(),
        ]);

        return $dispatch;
    }

    private function asClient(User $user, string $clientAbility): static
    {
        $token = $user->createToken('IAM test', ['*', $clientAbility], now()->addHour())->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
