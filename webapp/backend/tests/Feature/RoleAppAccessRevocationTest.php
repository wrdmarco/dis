<?php

namespace Tests\Feature;

use App\Events\UserAuthorizationChanged;
use App\Models\AvailabilityStatus;
use App\Models\FcmToken;
use App\Models\MobilePairingCode;
use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RoleAppAccessRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([UserAuthorizationChanged::class]);
    }

    public function test_permission_change_preserves_sessions_push_and_availability(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('permission-change');
        $originalPermission = $this->permission('deployments.assigned.view');
        $replacementPermission = $this->permission('deployments.respond');
        $role = $this->role('permission-change', operator: true, admin: true);
        $role->permissions()->attach($originalPermission->id);
        $target->roles()->attach($role->id, ['created_at' => now()]);
        $operatorToken = $this->accessToken($target, 'operator', 'client:operator');
        $device = $this->device($target, $operatorToken, 'operator');
        $authorizationToken = $target->createToken(
            'Role permission request',
            ['*', 'client:operator'],
            now()->addHour(),
        )->plainTextToken;
        $this->webSession($target, 'permission-change-session');
        $this->available($target);

        $this->withToken($authorizationToken)->getJson('/api/deployments')->assertOk();

        app(RoleService::class)->update($role, [
            'permission_ids' => [$replacementPermission->id],
        ], $actor);

        $this->withToken($authorizationToken)->getJson('/api/deployments')->assertForbidden();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $operatorToken->id]);
        $this->assertDatabaseHas('fcm_tokens', ['id' => $device->id, 'is_active' => true]);
        $this->assertDatabaseHas('sessions', ['id' => 'permission-change-session']);
        $this->assertTrue($target->refresh()->push_enabled);
        $this->assertTrue((bool) AvailabilityStatus::query()->latestPerUser()->where('user_id', $target->id)->value('is_available'));
        $this->assertFalse($role->permissions()->whereKey($originalPermission->id)->exists());
        $this->assertTrue($role->permissions()->whereKey($replacementPermission->id)->exists());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'users.role_definition_app_access_changed',
            'target_id' => $target->id,
        ]);
        $this->assertAuthorizationChangedFor($target);
    }

    public function test_losing_operator_access_revokes_only_operator_state_and_forces_unavailability(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('operator-loss');
        $role = $this->role('operator-loss', operator: true, admin: true);
        $target->roles()->attach($role->id, ['created_at' => now()]);
        $operatorToken = $this->accessToken($target, 'operator', 'client:operator');
        $operatorDevice = $this->device($target, $operatorToken, 'operator');
        $webToken = $this->accessToken($target, 'web', 'client:web');
        $operatorPairing = $this->pairingCode($target, 'operator');
        $adminPairing = $this->pairingCode($target, 'admin');
        $this->webSession($target, 'operator-loss-session');
        $this->available($target);

        app(RoleService::class)->update($role, [
            'can_use_operator_app' => false,
        ], $actor);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $operatorToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $webToken->id]);
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $operatorDevice->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('sessions', ['id' => 'operator-loss-session']);
        $this->assertDatabaseMissing('mobile_pairing_codes', ['id' => $operatorPairing->id]);
        $this->assertDatabaseHas('mobile_pairing_codes', ['id' => $adminPairing->id]);
        $this->assertFalse($target->refresh()->push_enabled);
        $latestStatus = AvailabilityStatus::query()->latestPerUser()->where('user_id', $target->id)->firstOrFail();
        $this->assertSame('unavailable', $latestStatus->status);
        $this->assertTrue($latestStatus->is_system_applied);
        $this->assertAuthorizationChangedFor($target);
    }

    public function test_losing_admin_access_preserves_operator_push_and_availability(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('admin-loss');
        $role = $this->role('admin-loss', operator: true, admin: true);
        $target->roles()->attach($role->id, ['created_at' => now()]);
        $operatorToken = $this->accessToken($target, 'operator', 'client:operator');
        $operatorDevice = $this->device($target, $operatorToken, 'operator');
        $webToken = $this->accessToken($target, 'web', 'client:web');
        $this->webSession($target, 'admin-loss-session');
        $this->available($target);

        app(RoleService::class)->update($role, [
            'can_use_admin_app' => false,
        ], $actor);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $operatorToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $webToken->id]);
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $operatorDevice->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-loss-session']);
        $this->assertTrue($target->refresh()->push_enabled);
        $this->assertTrue((bool) AvailabilityStatus::query()->latestPerUser()->where('user_id', $target->id)->value('is_available'));
        $this->assertAuthorizationChangedFor($target);
    }

    public function test_gaining_admin_access_revokes_only_web_sessions_for_mfa_step_up(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('admin-gain');
        $role = $this->role('admin-gain', operator: true, admin: false);
        $target->roles()->attach($role->id, ['created_at' => now()]);
        $operatorToken = $this->accessToken($target, 'operator', 'client:operator');
        $operatorDevice = $this->device($target, $operatorToken, 'operator');
        $webToken = $this->accessToken($target, 'web', 'client:web');
        $this->webSession($target, 'admin-gain-session');
        $this->available($target);
        $originalAuthSessionVersion = (int) $target->auth_session_version;

        app(RoleService::class)->update($role, [
            'can_use_admin_app' => true,
        ], $actor);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $operatorToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $webToken->id]);
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $operatorDevice->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('sessions', ['id' => 'admin-gain-session']);
        $this->assertSame($originalAuthSessionVersion + 1, (int) $target->refresh()->auth_session_version);
        $this->assertTrue($target->push_enabled);
        $this->assertTrue((bool) AvailabilityStatus::query()->latestPerUser()->where('user_id', $target->id)->value('is_available'));
        $this->assertAuthorizationChangedFor($target);
    }

    public function test_role_replacement_preserves_authentication_when_effective_app_access_remains(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('role-replacement');
        $originalRole = $this->role('role-replacement-original', operator: true, admin: true);
        $replacementRole = $this->role('role-replacement-new', operator: true, admin: true);
        $target->roles()->attach($originalRole->id, ['created_at' => now()]);
        $operatorToken = $this->accessToken($target, 'operator', 'client:operator');
        $device = $this->device($target, $operatorToken, 'operator');
        $this->webSession($target, 'role-replacement-session');
        $this->available($target);

        app(UserService::class)->update($target, [
            'role_ids' => [$replacementRole->id],
        ], $actor);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $operatorToken->id]);
        $this->assertDatabaseHas('fcm_tokens', ['id' => $device->id, 'is_active' => true]);
        $this->assertDatabaseHas('sessions', ['id' => 'role-replacement-session']);
        $this->assertTrue($target->refresh()->push_enabled);
        $this->assertTrue((bool) AvailabilityStatus::query()->latestPerUser()->where('user_id', $target->id)->value('is_available'));
        $this->assertAuthorizationChangedFor($target);
    }

    public function test_role_replacement_that_removes_operator_access_revokes_only_operator_state(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('role-replacement-operator-loss');
        $originalRole = $this->role('role-replacement-operator-original', operator: true, admin: true);
        $replacementRole = $this->role('role-replacement-operator-new', operator: false, admin: true);
        $target->roles()->attach($originalRole->id, ['created_at' => now()]);
        $operatorToken = $this->accessToken($target, 'operator', 'client:operator');
        $device = $this->device($target, $operatorToken, 'operator');
        $webToken = $this->accessToken($target, 'web', 'client:web');
        $this->webSession($target, 'role-replacement-operator-session');
        $this->available($target);

        app(UserService::class)->update($target, [
            'role_ids' => [$replacementRole->id],
        ], $actor);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $operatorToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $webToken->id]);
        $this->assertDatabaseHas('fcm_tokens', ['id' => $device->id, 'is_active' => false]);
        $this->assertDatabaseHas('sessions', ['id' => 'role-replacement-operator-session']);
        $this->assertFalse($target->refresh()->push_enabled);
        $this->assertSame(
            'unavailable',
            AvailabilityStatus::query()->latestPerUser()->where('user_id', $target->id)->value('status'),
        );
        $this->assertAuthorizationChangedFor($target);
    }

    public function test_role_replacement_that_grants_admin_access_revokes_only_web_sessions(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('role-replacement-admin-gain');
        $originalRole = $this->role('role-replacement-admin-original', operator: true, admin: false);
        $replacementRole = $this->role('role-replacement-admin-new', operator: true, admin: true);
        $target->roles()->attach($originalRole->id, ['created_at' => now()]);
        $operatorToken = $this->accessToken($target, 'operator', 'client:operator');
        $device = $this->device($target, $operatorToken, 'operator');
        $this->webSession($target, 'role-replacement-admin-session');
        $this->available($target);
        $originalAuthSessionVersion = (int) $target->auth_session_version;

        app(UserService::class)->update($target, [
            'role_ids' => [$replacementRole->id],
        ], $actor);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $operatorToken->id]);
        $this->assertDatabaseHas('fcm_tokens', ['id' => $device->id, 'is_active' => true]);
        $this->assertDatabaseMissing('sessions', ['id' => 'role-replacement-admin-session']);
        $this->assertSame($originalAuthSessionVersion + 1, (int) $target->refresh()->auth_session_version);
        $this->assertTrue($target->push_enabled);
        $this->assertTrue((bool) AvailabilityStatus::query()->latestPerUser()->where('user_id', $target->id)->value('is_available'));
        $this->assertAuthorizationChangedFor($target);
    }

    public function test_role_definition_change_preserves_operator_state_when_another_role_keeps_access(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('overlapping-operator-access');
        $changedRole = $this->role('overlapping-operator-changed', operator: true, admin: true);
        $retainedRole = $this->role('overlapping-operator-retained', operator: true, admin: true);
        $target->roles()->attach([
            $changedRole->id => ['created_at' => now()],
            $retainedRole->id => ['created_at' => now()],
        ]);
        $operatorToken = $this->accessToken($target, 'operator', 'client:operator');
        $device = $this->device($target, $operatorToken, 'operator');
        $this->webSession($target, 'overlapping-operator-session');
        $this->available($target);

        app(RoleService::class)->update($changedRole, [
            'can_use_operator_app' => false,
        ], $actor);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $operatorToken->id]);
        $this->assertDatabaseHas('fcm_tokens', ['id' => $device->id, 'is_active' => true]);
        $this->assertDatabaseHas('sessions', ['id' => 'overlapping-operator-session']);
        $this->assertTrue($target->refresh()->push_enabled);
        $this->assertTrue((bool) AvailabilityStatus::query()->latestPerUser()->where('user_id', $target->id)->value('is_available'));
        $this->assertAuthorizationChangedFor($target);
    }

    public function test_metadata_and_no_op_permission_updates_do_not_broadcast_authorization_changes(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('metadata-change');
        $permission = $this->permission('deployments.assigned.view');
        $role = $this->role('metadata-change', operator: true, admin: true);
        $role->permissions()->attach($permission->id);
        $target->roles()->attach($role->id, ['created_at' => now()]);

        app(RoleService::class)->update($role, [
            'display_name' => 'Only metadata changed',
            'permission_ids' => [$permission->id],
        ], $actor);

        Event::assertNotDispatched(UserAuthorizationChanged::class);
    }

    public function test_direct_role_attach_and_detach_each_broadcast_an_authorization_change(): void
    {
        $actor = $this->roleManager();
        $target = $this->user('direct-role-delta');
        $baseRole = $this->role('direct-role-base', operator: true, admin: true);
        $additionalRole = $this->role('direct-role-additional', operator: true, admin: true);
        $target->roles()->attach($baseRole->id, ['created_at' => now()]);

        app(UserService::class)->assignRole($target, $additionalRole, $actor);
        app(UserService::class)->removeRole($target, $additionalRole, $actor);

        Event::assertDispatchedTimes(UserAuthorizationChanged::class, 2);
    }

    public function test_authorization_change_event_exposes_only_the_target_user_identity(): void
    {
        $target = $this->user('authorization-event');
        $event = new UserAuthorizationChanged((string) $target->id);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-users.'.$target->id, $channels[0]->name);
        $this->assertSame('authorization.changed', $event->broadcastAs());
        $this->assertSame(['user_id' => (string) $target->id], $event->broadcastWith());
    }

    private function roleManager(): User
    {
        $actor = $this->user('role-manager');
        $rolesManage = $this->permission('roles.manage');
        $systemAdministrator = $this->role(Role::SYSTEM_ADMINISTRATOR, operator: false, admin: true);
        $systemAdministrator->permissions()->attach($rolesManage->id);
        $actor->roles()->attach($systemAdministrator->id, ['created_at' => now()]);

        return $actor;
    }

    private function user(string $suffix): User
    {
        return User::query()->create([
            'name' => 'Role access '.$suffix,
            'first_name' => 'Role',
            'last_name' => 'Access '.$suffix,
            'email' => "role-access-{$suffix}@example.test",
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function role(string $name, bool $operator, bool $admin): Role
    {
        return Role::query()->create([
            'name' => $name,
            'display_name' => $name,
            'can_use_operator_app' => $operator,
            'can_use_admin_app' => $admin,
        ]);
    }

    private function permission(string $name): Permission
    {
        return Permission::query()->firstOrCreate(
            ['name' => $name],
            [
                'category' => 'role-access-test',
                'display_name' => $name,
                'description' => 'Role app access revocation test permission.',
            ],
        );
    }

    private function accessToken(User $user, string $name, string $ability): PersonalAccessToken
    {
        return $user->createToken(
            'Role access '.$name,
            ['*', $ability],
            now()->addHour(),
        )->accessToken;
    }

    private function device(User $user, PersonalAccessToken $accessToken, string $clientType): FcmToken
    {
        $providerToken = 'role-access-provider-'.$clientType.'-'.$user->id;

        return FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'role-access-device-'.$clientType,
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'personal_access_token_id' => $accessToken->id,
            'platform' => $clientType === 'operator' ? 'android' : 'ios',
            'client_type' => $clientType,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }

    private function pairingCode(User $user, string $clientType): MobilePairingCode
    {
        return MobilePairingCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', 'role-access-'.$clientType.'-'.$user->id),
            'client_type' => $clientType,
            'expires_at' => now()->addMinute(),
        ]);
    }

    private function webSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '192.0.2.1',
            'user_agent' => 'Role access test',
            'payload' => 'role-access-session',
            'last_activity' => now()->timestamp,
        ]);
    }

    private function available(User $user): void
    {
        AvailabilityStatus::query()->create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => 'available',
            'is_available' => true,
            'is_system_applied' => false,
            'effective_at' => now()->subMinute(),
        ]);
    }

    private function assertAuthorizationChangedFor(User $user): void
    {
        Event::assertDispatched(
            UserAuthorizationChanged::class,
            fn (UserAuthorizationChanged $event): bool => $event->userId === $user->id,
        );
    }
}
