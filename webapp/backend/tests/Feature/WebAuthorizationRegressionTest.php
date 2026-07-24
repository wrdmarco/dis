<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Certification;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WebAuthorizationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_api_denies_anonymous_and_unprivileged_callers_but_allows_the_required_permission(): void
    {
        $this->getJson('/api/admin/settings')->assertUnauthorized();

        $unprivileged = $this->user('unprivileged@example.test');
        $this->grant($unprivileged, [], operator: false, admin: true);
        $this->asClient($unprivileged, 'client:web')
            ->getJson('/api/admin/settings')
            ->assertForbidden();

        $privileged = $this->user('settings-manager@example.test');
        $this->grant($privileged, ['settings.manage'], operator: false, admin: true);
        $response = $this->asClient($privileged, 'client:web')
            ->getJson('/api/admin/settings');

        $this->assertSame(200, $response->status(), $response->getContent());
    }

    public function test_team_management_no_longer_requires_legacy_type_or_parent_fields(): void
    {
        $actor = $this->user('team-manager@example.test');
        $this->grant($actor, ['teams.manage'], operator: false, admin: true);

        $response = $this->asClient($actor, 'client:web')
            ->postJson('/api/admin/teams', [
                'code' => 'NO-LEGACY-FIELDS',
                'name' => 'Team zonder legacyvelden',
                'is_operational' => true,
                'alert_team_ids' => [],
                'required_certification_ids' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'NO-LEGACY-FIELDS')
            ->assertJsonPath('data.type', 'base')
            ->assertJsonPath('data.parent_team_id', null);

        $this->assertDatabaseHas('teams', [
            'id' => $response->json('data.id'),
            'type' => 'base',
            'parent_team_id' => null,
        ]);
    }

    public function test_team_update_without_legacy_fields_preserves_existing_compatibility_values(): void
    {
        $actor = $this->user('legacy-team-manager@example.test');
        $this->grant($actor, ['teams.manage'], operator: false, admin: true);
        $parent = Team::query()->create([
            'code' => 'LEGACY-PARENT',
            'name' => 'Legacy parent',
            'type' => 'base',
            'is_operational' => true,
        ]);
        $team = Team::query()->create([
            'code' => 'LEGACY-SUBSET',
            'name' => 'Legacy subset',
            'type' => 'subset',
            'parent_team_id' => $parent->id,
            'is_operational' => true,
        ]);

        $this->asClient($actor, 'client:web')
            ->patchJson('/api/admin/teams/'.$team->id, [
                'name' => 'Bijgewerkte naam',
                'is_operational' => false,
                'alert_team_ids' => [],
                'required_certification_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.type', 'subset')
            ->assertJsonPath('data.parent_team_id', $parent->id);

        $team->refresh();
        $this->assertSame('subset', $team->type);
        $this->assertSame($parent->id, $team->parent_team_id);
    }

    public function test_nested_user_certification_id_cannot_escape_the_parent_user_scope(): void
    {
        $actor = $this->user('certification-manager@example.test');
        $victim = $this->user('victim@example.test');
        $other = $this->user('other@example.test');
        $this->grant($actor, ['certifications.manage'], operator: false, admin: true);

        $certification = Certification::query()->create([
            'code' => 'SEC-IDOR',
            'name' => 'Security IDOR test',
            'is_required_for_dispatch' => false,
            'warning_days_before_expiry' => 30,
        ]);
        $otherCertification = UserCertification::query()->create([
            'user_id' => $other->id,
            'certification_id' => $certification->id,
            'issued_at' => now()->subDay()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        $this->asClient($actor, 'client:web')
            ->patchJson('/api/users/'.$victim->id.'/certifications/'.$otherCertification->id, [
                'status' => 'revoked',
            ])
            ->assertNotFound();

        $this->assertSame('active', $otherCertification->refresh()->status);
    }

    public function test_operator_cannot_modify_an_asset_assigned_to_another_user_through_mine_route(): void
    {
        $operator = $this->user('operator@example.test');
        $owner = $this->user('owner@example.test');
        $this->grant($operator, [], operator: true, admin: false);

        $asset = Asset::query()->create([
            'asset_tag' => 'SEC-ASSET-001',
            'name' => 'Security test asset',
            'type' => 'support_equipment',
            'status' => 'ready',
        ]);
        AssetAssignment::query()->create([
            'asset_id' => $asset->id,
            'user_id' => $owner->id,
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
        ]);

        $this->asClient($operator, 'client:operator')
            ->patchJson('/api/assets/'.$asset->id.'/mine', [
                'status' => 'maintenance',
            ])
            ->assertForbidden();

        $this->assertSame('ready', $asset->refresh()->status);
    }

    public function test_operator_cannot_download_an_unassigned_incident_report_by_guessing_its_id(): void
    {
        $operator = $this->user('report-operator@example.test');
        $creator = $this->user('report-creator@example.test');
        $this->grant($operator, ['incidents.view'], operator: true, admin: false);
        $incident = Incident::query()->create([
            'reference' => 'SEC-REPORT-IDOR',
            'title' => 'Unassigned security report',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => false,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
        ]);

        $response = $this->asClient($operator, 'client:operator')
            ->getJson('/api/incidents/'.$incident->id.'/report.pdf');

        $response->assertForbidden();
        $this->assertStringNotContainsString($incident->title, $response->getContent());
    }

    public function test_operator_report_listing_does_not_include_unassigned_incidents(): void
    {
        $operator = $this->user('report-list-operator@example.test');
        $creator = $this->user('report-list-creator@example.test');
        $this->grant($operator, ['incidents.view'], operator: true, admin: false);
        $incident = Incident::query()->create([
            'reference' => 'SEC-REPORT-LIST-IDOR',
            'title' => 'Unassigned closed report',
            'priority' => 'normal',
            'status' => 'resolved',
            'is_test' => false,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
        ]);

        $response = $this->asClient($operator, 'client:operator')
            ->getJson('/api/reports/incidents')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertStringNotContainsString($incident->title, $response->getContent());
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Security Test User',
            'first_name' => 'Security',
            'last_name' => 'Test User',
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
    private function grant(User $user, array $permissionNames, bool $operator, bool $admin): void
    {
        $role = Role::query()->create([
            'name' => 'security-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Security test role',
            'can_use_operator_app' => $operator,
            'can_use_admin_app' => $admin,
        ]);
        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'category' => 'security-test',
                    'display_name' => $permissionName,
                    'description' => 'Security test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function asClient(User $user, string $clientAbility): static
    {
        $token = $user->createToken('Security regression client', ['*', $clientAbility], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
