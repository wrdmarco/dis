<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Deployment;
use App\Models\DroneType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AssetTypeUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
    }

    public function test_user_can_change_the_type_of_a_self_created_asset_and_dependent_drone_fields_are_cleared(): void
    {
        $owner = $this->user('self-created-owner@example.test');
        $droneType = $this->droneType('Capable', spotlight: true, speaker: true);
        $asset = $this->asset('SELF-001', 'drone', $droneType, spotlight: true, speaker: true);
        $this->assign($asset, $owner, $owner);

        $this->asWebClient($owner)
            ->patchJson('/api/assets/'.$asset->id.'/mine', [
                'name' => 'Ondersteuningskoffer',
                'type' => 'support_equipment',
                'drone_type_id' => $droneType->id,
                'has_spotlight' => true,
                'has_speaker' => true,
                'status' => 'maintenance',
                'serial_number' => 'SELF-UPDATED-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ondersteuningskoffer')
            ->assertJsonPath('data.type', 'support_equipment')
            ->assertJsonPath('data.drone_type_id', null)
            ->assertJsonPath('data.has_spotlight', false)
            ->assertJsonPath('data.has_speaker', false)
            ->assertJsonPath('data.status', 'maintenance')
            ->assertJsonPath('data.serial_number', 'SELF-UPDATED-001');

        $asset->refresh();
        $this->assertNull($asset->drone_type_id);
        $this->assertFalse($asset->has_spotlight);
        $this->assertFalse($asset->has_speaker);
    }

    public function test_user_can_change_a_self_created_non_drone_asset_into_a_valid_drone(): void
    {
        $owner = $this->user('self-created-drone-owner@example.test');
        $droneType = $this->droneType('Convertible', spotlight: true, speaker: false);
        $asset = $this->asset('SELF-002', 'battery');
        $this->assign($asset, $owner, $owner);

        $this->asWebClient($owner)
            ->patchJson('/api/assets/'.$asset->id.'/mine', [
                'type' => 'drone',
                'drone_type_id' => $droneType->id,
                'has_spotlight' => true,
                'has_speaker' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.type', 'drone')
            ->assertJsonPath('data.drone_type_id', $droneType->id)
            ->assertJsonPath('data.has_spotlight', true)
            ->assertJsonPath('data.has_speaker', false);
    }

    public function test_identity_fields_cannot_be_changed_through_mine_for_an_admin_assigned_asset(): void
    {
        $owner = $this->user('assigned-owner@example.test');
        $manager = $this->user('assigning-manager@example.test');
        $asset = $this->asset('ASSIGNED-001', 'battery');
        $this->assign($asset, $owner, $manager);

        $response = $this->asWebClient($owner)
            ->patchJson('/api/assets/'.$asset->id.'/mine', [
                'name' => 'Onbevoegd gewijzigd',
                'type' => 'vehicle',
                'serial_number' => 'UNAUTHORISED-SERIAL',
                'status' => 'maintenance',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertNotEmpty($response->json('error.details.name'));
        $this->assertNotEmpty($response->json('error.details.type'));
        $this->assertNotEmpty($response->json('error.details.serial_number'));

        $asset->refresh();
        $this->assertSame('Asset ASSIGNED-001', $asset->name);
        $this->assertSame('battery', $asset->type);
        $this->assertSame('SERIAL-ASSIGNED-001', $asset->serial_number);
        $this->assertSame('ready', $asset->status);
    }

    public function test_converting_an_asset_to_a_drone_requires_a_drone_type_on_both_update_routes(): void
    {
        $owner = $this->user('required-type-owner@example.test');
        $asset = $this->asset('REQUIRED-001', 'battery');
        $this->assign($asset, $owner, $owner);

        $ownerResponse = $this->asWebClient($owner)
            ->patchJson('/api/assets/'.$asset->id.'/mine', ['type' => 'drone'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertNotEmpty($ownerResponse->json('error.details.drone_type_id'));

        $manager = $this->user('required-type-manager@example.test');
        $this->grantPermission($manager, 'assets.manage');

        $managerResponse = $this->asWebClient($manager)
            ->patchJson('/api/assets/'.$asset->id, ['type' => 'drone'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertNotEmpty($managerResponse->json('error.details.drone_type_id'));

        $this->assertSame('battery', $asset->refresh()->type);
    }

    public function test_manager_can_change_drone_subtype_while_supported_flags_are_preserved_and_unsupported_flags_are_cleared(): void
    {
        $manager = $this->user('asset-manager@example.test');
        $this->grantPermission($manager, 'assets.manage');
        $originalType = $this->droneType('Original', spotlight: true, speaker: true);
        $compatibleType = $this->droneType('Compatible', spotlight: true, speaker: true);
        $basicType = $this->droneType('Basic', spotlight: false, speaker: false);
        $asset = $this->asset('ADMIN-001', 'drone', $originalType, spotlight: true, speaker: true);

        $this->asWebClient($manager)
            ->patchJson('/api/assets/'.$asset->id, [
                'type' => 'drone',
                'drone_type_id' => $compatibleType->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.drone_type_id', $compatibleType->id)
            ->assertJsonPath('data.has_spotlight', true)
            ->assertJsonPath('data.has_speaker', true);

        $this->asWebClient($manager)
            ->patchJson('/api/assets/'.$asset->id, [
                'type' => 'drone',
                'drone_type_id' => $basicType->id,
                'has_spotlight' => true,
                'has_speaker' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.drone_type_id', $basicType->id)
            ->assertJsonPath('data.has_spotlight', false)
            ->assertJsonPath('data.has_speaker', false);
    }

    public function test_assignment_fails_closed_for_overdue_unready_and_already_assigned_assets(): void
    {
        $manager = $this->user('asset-safety-manager@example.test');
        $owner = $this->user('asset-safety-owner@example.test');
        $this->grantPermission($manager, 'assets.manage');

        $overdue = $this->asset('OVERDUE-001', 'support_equipment');
        $overdue->update(['maintenance_due_at' => today()->subDay()]);
        $this->asWebClient($manager)
            ->postJson('/api/assets/'.$overdue->id.'/assign', ['user_id' => $owner->id])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['asset']]]);
        $this->assertFalse($overdue->assignments()->exists());

        $unavailable = $this->asset('UNAVAILABLE-001', 'support_equipment');
        $unavailable->update(['status' => 'unavailable', 'maintenance_due_at' => today()->addDay()]);
        $this->asWebClient($manager)
            ->postJson('/api/assets/'.$unavailable->id.'/assign', ['user_id' => $owner->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['asset']]]);

        $assigned = $this->asset('ASSIGNED-GUARD-001', 'support_equipment');
        $assigned->update(['status' => 'assigned', 'maintenance_due_at' => today()->addDay()]);
        $this->assign($assigned, $owner, $manager);
        $this->asWebClient($manager)
            ->postJson('/api/assets/'.$assigned->id.'/assign', ['user_id' => $manager->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['asset']]]);
        $this->assertSame(1, $assigned->assignments()->whereNull('released_at')->count());
    }

    public function test_assignment_requires_exactly_one_user_or_deployment_target(): void
    {
        $manager = $this->user('asset-target-manager@example.test');
        $owner = $this->user('asset-target-owner@example.test');
        $this->grantPermission($manager, 'assets.manage');
        $deployment = Deployment::query()->create([
            'reference' => 'ASSET-TARGET-001',
            'title' => 'Asset target validation',
            'priority' => 'normal',
            'status' => 'draft',
            'is_test' => false,
            'created_by' => $manager->id,
            'created_by_name' => $manager->name,
            'created_by_email' => $manager->email,
            'opened_at' => now(),
        ]);
        $missingTarget = $this->asset('TARGET-MISSING-001', 'support_equipment');
        $bothTargets = $this->asset('TARGET-BOTH-001', 'support_equipment');
        $validTarget = $this->asset('TARGET-VALID-001', 'support_equipment');
        $validTarget->update(['maintenance_due_at' => today()]);

        $this->asWebClient($manager)
            ->postJson('/api/assets/'.$missingTarget->id.'/assign', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['deployment_id', 'user_id']]]);
        $this->asWebClient($manager)
            ->postJson('/api/assets/'.$bothTargets->id.'/assign', [
                'deployment_id' => $deployment->id,
                'user_id' => $owner->id,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['deployment_id', 'user_id']]]);
        $this->asWebClient($manager)
            ->postJson('/api/assets/'.$validTarget->id.'/assign', ['user_id' => $owner->id])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $owner->id);
        $this->assertSame('assigned', $validTarget->refresh()->status);
    }

    public function test_release_never_promotes_an_overdue_assignment_back_to_ready(): void
    {
        $manager = $this->user('asset-release-manager@example.test');
        $owner = $this->user('asset-release-owner@example.test');
        $this->grantPermission($manager, 'assets.manage');
        $asset = $this->asset('RELEASE-OVERDUE-001', 'support_equipment');
        $asset->update([
            'status' => 'assigned',
            'maintenance_due_at' => today()->subDay(),
        ]);
        $this->assign($asset, $owner, $manager);

        $this->asWebClient($manager)
            ->postJson('/api/assets/'.$asset->id.'/release')
            ->assertOk()
            ->assertJsonPath('data.status', 'maintenance')
            ->assertJsonPath('data.effective_status', 'maintenance')
            ->assertJsonPath('data.is_effectively_ready', false)
            ->assertJsonPath('data.maintenance_overdue', true);

        $this->assertSame('maintenance', $asset->refresh()->status);
        $this->assertNotNull($asset->assignments()->sole()->released_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'assets.released',
            'target_id' => $asset->id,
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Asset Test User',
            'first_name' => 'Asset',
            'last_name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function droneType(string $model, bool $spotlight, bool $speaker): DroneType
    {
        return DroneType::query()->create([
            'manufacturer' => 'Test',
            'model' => $model,
            'has_thermal' => false,
            'has_spotlight' => $spotlight,
            'has_speaker' => $speaker,
            'is_active' => true,
        ]);
    }

    private function asset(
        string $tag,
        string $type,
        ?DroneType $droneType = null,
        bool $spotlight = false,
        bool $speaker = false,
    ): Asset {
        return Asset::query()->create([
            'asset_tag' => $tag,
            'name' => 'Asset '.$tag,
            'type' => $type,
            'drone_type_id' => $droneType?->id,
            'has_spotlight' => $spotlight,
            'has_speaker' => $speaker,
            'status' => 'ready',
            'serial_number' => 'SERIAL-'.$tag,
        ]);
    }

    private function assign(Asset $asset, User $owner, User $assigner): void
    {
        AssetAssignment::query()->create([
            'asset_id' => $asset->id,
            'user_id' => $owner->id,
            'assigned_by' => $assigner->id,
            'assigned_at' => now(),
        ]);
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $role = Role::query()->create([
            'name' => 'asset-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Asset test role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['name' => $permissionName],
            [
                'category' => 'asset-test',
                'display_name' => $permissionName,
                'description' => 'Asset test permission',
            ],
        );
        $role->permissions()->attach($permission->id, ['created_at' => now()]);
        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken('Asset test web client', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
