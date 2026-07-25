<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AvailabilityOverride;
use App\Models\AvailabilityStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserVacation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class VacationAvailabilityOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_and_delete_only_their_own_all_day_planning(): void
    {
        $owner = $this->user('vacation-owner@example.test');
        $other = $this->user('vacation-other@example.test');
        $startsAt = today()->toDateString();
        $endsAt = today()->addDay()->toDateString();

        $created = $this->asWebClient($owner)
            ->postJson('/api/vacations/mine', [
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'note' => 'Privénotitie die niet in auditmetadata hoort.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.user', null)
            ->json('data');

        $vacationId = (string) $created['id'];
        $this->assertDatabaseHas('availability_overrides', [
            'id' => $vacationId,
            'user_id' => $owner->id,
            'day_part' => 'all_day',
            'is_available' => false,
        ]);
        $this->assertSame('unavailable', $this->latestStatus($owner)?->status);

        $this->asWebClient($other)
            ->getJson('/api/vacations/mine')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->asWebClient($other)
            ->patchJson('/api/vacations/'.$vacationId, ['is_available' => true])
            ->assertForbidden();
        $this->asWebClient($other)
            ->deleteJson('/api/vacations/'.$vacationId)
            ->assertForbidden();

        $this->asWebClient($owner)
            ->patchJson('/api/vacations/'.$vacationId, [
                'is_available' => true,
                'note' => 'Nieuwe gevoelige notitie.',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.note', 'Nieuwe gevoelige notitie.');
        $this->assertSame('available', $this->latestStatus($owner)?->status);

        $audit = AuditLog::query()
            ->where('action', 'availability.override_updated')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertContains('note', $audit->metadata['changed_fields']);
        $this->assertStringNotContainsString('Nieuwe gevoelige notitie.', json_encode($audit->metadata, JSON_THROW_ON_ERROR));

        $this->asWebClient($owner)
            ->deleteJson('/api/vacations/'.$vacationId)
            ->assertOk()
            ->assertJsonPath('data.id', $vacationId);
        $this->assertDatabaseMissing('availability_overrides', ['id' => $vacationId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'availability.override_deleted']);

        $replacementId = (string) $this->asWebClient($owner)
            ->postJson('/api/vacations/mine', [
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'is_available' => false,
            ])
            ->assertCreated()
            ->json('data.id');
        $this->assertSame('unavailable', $this->latestStatus($owner)?->status);
        $this->asWebClient($owner)->deleteJson('/api/vacations/'.$replacementId)->assertOk();
        $this->assertSame('available', $this->latestStatus($owner)?->status);
    }

    public function test_update_requires_availability_and_valid_date_order(): void
    {
        $user = $this->user('vacation-validation@example.test');
        $vacation = $this->override($user, today()->toDateString(), today()->addDay()->toDateString(), false);

        $this->asWebClient($user)
            ->patchJson('/api/vacations/'.$vacation->id, ['note' => 'Geen beschikbaarheid'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['is_available']]]);

        $this->asWebClient($user)
            ->patchJson('/api/vacations/'.$vacation->id, [
                'starts_at' => today()->addDays(3)->toDateString(),
                'ends_at' => today()->addDay()->toDateString(),
                'is_available' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['ends_at']]]);
    }

    public function test_dedicated_vacation_permissions_separate_read_and_management(): void
    {
        $target = $this->user('vacation-target@example.test');
        $manager = $this->user('vacation-manager@example.test');
        $viewer = $this->user('vacation-viewer@example.test');
        $statusManager = $this->user('vacation-status-manager@example.test');
        $statusViewer = $this->user('vacation-status-viewer@example.test');
        $userManager = $this->user('vacation-user-manager@example.test');
        $this->grant($manager, ['vacations.manage']);
        $this->grant($viewer, ['vacations.view']);
        $this->grant($statusManager, ['status.override']);
        $this->grant($statusViewer, ['status.view']);
        $this->grant($userManager, ['users.manage']);

        $created = $this->asWebClient($manager)
            ->postJson('/api/users/'.$target->id.'/vacations', [
                'starts_at' => today()->addDay()->toDateString(),
                'ends_at' => today()->addDays(2)->toDateString(),
                'is_available' => true,
                'note' => 'Beschikbaar tijdens vakantieperiode.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $target->id)
            ->assertJsonPath('data.status', 'scheduled')
            ->json('data');
        $vacationId = (string) $created['id'];

        $this->asWebClient($manager)
            ->getJson('/api/users/'.$target->id.'/vacations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $vacationId);
        $this->asWebClient($manager)
            ->getJson('/api/vacations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $vacationId);
        $this->asWebClient($manager)
            ->patchJson('/api/vacations/'.$vacationId, [
                'starts_at' => today()->addDays(3)->toDateString(),
                'ends_at' => today()->addDays(4)->toDateString(),
                'is_available' => false,
                'note' => 'Door de admin aangepast.',
            ])
            ->assertOk()
            ->assertJsonPath('data.starts_at', today()->addDays(3)->toDateString())
            ->assertJsonPath('data.ends_at', today()->addDays(4)->toDateString())
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.note', 'Door de admin aangepast.');

        $this->asWebClient($viewer)->getJson('/api/vacations')->assertOk();
        $this->asWebClient($viewer)
            ->postJson('/api/users/'.$target->id.'/vacations', [
                'starts_at' => today()->addDays(4)->toDateString(),
                'ends_at' => today()->addDays(5)->toDateString(),
            ])
            ->assertForbidden();
        $this->asWebClient($viewer)
            ->patchJson('/api/vacations/'.$vacationId, ['is_available' => true])
            ->assertForbidden();
        $this->asWebClient($viewer)
            ->deleteJson('/api/vacations/'.$vacationId)
            ->assertForbidden();

        $this->asWebClient($statusViewer)
            ->getJson('/api/users/'.$target->id.'/vacations')
            ->assertForbidden();
        $this->asWebClient($statusManager)
            ->getJson('/api/vacations')
            ->assertForbidden();
        $this->asWebClient($statusManager)
            ->postJson('/api/users/'.$target->id.'/vacations', [
                'starts_at' => today()->addDays(4)->toDateString(),
                'ends_at' => today()->addDays(5)->toDateString(),
            ])
            ->assertForbidden();
        $this->asWebClient($statusManager)
            ->patchJson('/api/vacations/'.$vacationId, ['is_available' => true])
            ->assertForbidden();
        $this->asWebClient($statusManager)
            ->deleteJson('/api/vacations/'.$vacationId)
            ->assertForbidden();

        $this->asWebClient($userManager)
            ->getJson('/api/users/'.$target->id.'/vacations')
            ->assertForbidden();
        $this->asWebClient($userManager)
            ->postJson('/api/users/'.$target->id.'/vacations', [
                'starts_at' => today()->addDays(4)->toDateString(),
                'ends_at' => today()->addDays(5)->toDateString(),
            ])
            ->assertForbidden();
        $this->asWebClient($userManager)
            ->patchJson('/api/vacations/'.$vacationId, ['is_available' => true])
            ->assertForbidden();
        $this->asWebClient($userManager)
            ->deleteJson('/api/vacations/'.$vacationId)
            ->assertForbidden();

        $this->asWebClient($manager)
            ->deleteJson('/api/vacations/'.$vacationId)
            ->assertOk();
        $this->assertDatabaseMissing('availability_overrides', ['id' => $vacationId]);
    }

    public function test_vacation_alias_never_exposes_or_mutates_day_part_overrides(): void
    {
        $target = $this->user('day-part-target@example.test');
        $manager = $this->user('day-part-manager@example.test');
        $this->grant($manager, ['vacations.manage']);
        $morning = AvailabilityOverride::query()->create([
            'user_id' => $target->id,
            'starts_at' => today(),
            'ends_at' => today()->addDay(),
            'day_part' => 'morning',
            'is_available' => false,
            'created_by' => $manager->id,
        ]);

        $this->asWebClient($manager)
            ->getJson('/api/users/'.$target->id.'/vacations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->asWebClient($manager)
            ->patchJson('/api/vacations/'.$morning->id, ['is_available' => true])
            ->assertNotFound();
        $this->asWebClient($manager)
            ->deleteJson('/api/vacations/'.$morning->id)
            ->assertNotFound();
        $this->assertFalse((bool) $morning->refresh()->is_available);
    }

    public function test_legacy_migration_preserves_existing_override_and_rolls_back_unchanged_data_safely(): void
    {
        $user = $this->user('vacation-migration@example.test');
        $existing = $this->override($user, today()->toDateString(), today()->addDay()->toDateString(), true);
        $existing->forceFill(['note' => 'Bestaande planning blijft leidend.'])->save();
        $duplicateLegacy = UserVacation::query()->create([
            'user_id' => $user->id,
            'starts_at' => today(),
            'ends_at' => today()->addDay(),
            'status' => 'active',
            'note' => 'Mag bestaande planning niet overschrijven.',
            'created_by' => $user->id,
        ]);
        $newLegacy = UserVacation::query()->create([
            'user_id' => $user->id,
            'starts_at' => today()->addDays(3),
            'ends_at' => today()->addDays(4),
            'status' => 'scheduled',
            'note' => 'Wordt gemigreerd.',
            'created_by' => $user->id,
        ]);
        $closedLegacy = UserVacation::query()->create([
            'user_id' => $user->id,
            'starts_at' => today()->subDays(5),
            'ends_at' => today()->subDays(4),
            'status' => 'cancelled',
            'created_by' => $user->id,
        ]);

        $migration = require database_path('migrations/2026_07_25_000002_migrate_open_vacations_to_availability_overrides.php');
        $migration->up();

        $this->assertTrue((bool) $existing->refresh()->is_available);
        $this->assertSame('Bestaande planning blijft leidend.', $existing->note);
        $this->assertDatabaseHas('availability_overrides', [
            'id' => $newLegacy->id,
            'user_id' => $user->id,
            'day_part' => 'all_day',
            'is_available' => false,
        ]);
        $this->assertSame('migrated', $duplicateLegacy->refresh()->status);
        $this->assertSame('migrated', $newLegacy->refresh()->status);
        $this->assertSame('cancelled', $closedLegacy->refresh()->status);

        $migration->down();

        $this->assertTrue(Schema::hasTable('availability_overrides'));
        $this->assertFalse(Schema::hasTable('vacation_availability_migration_provenance'));
        $this->assertDatabaseHas('availability_overrides', [
            'id' => $existing->id,
            'is_available' => true,
            'note' => 'Bestaande planning blijft leidend.',
        ]);
        $this->assertDatabaseMissing('availability_overrides', ['id' => $newLegacy->id]);
        $this->assertSame('active', $duplicateLegacy->refresh()->status);
        $this->assertSame('scheduled', $newLegacy->refresh()->status);
        $this->assertSame('cancelled', $closedLegacy->refresh()->status);
    }

    public function test_legacy_migration_uses_a_new_override_id_when_an_unrelated_override_id_collides(): void
    {
        $user = $this->user('vacation-migration-collision@example.test');
        $legacy = UserVacation::query()->create([
            'user_id' => $user->id,
            'starts_at' => today()->addDays(3),
            'ends_at' => today()->addDays(4),
            'status' => 'scheduled',
            'note' => 'Moet ondanks de ID-botsing migreren.',
            'created_by' => $user->id,
        ]);
        DB::table('availability_overrides')->insert([
            'id' => $legacy->id,
            'user_id' => $user->id,
            'starts_at' => today()->addDays(10)->toDateString(),
            'ends_at' => today()->addDays(11)->toDateString(),
            'day_part' => 'all_day',
            'is_available' => true,
            'note' => 'Ongerelateerde bestaande planning.',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_25_000002_migrate_open_vacations_to_availability_overrides.php');
        $migration->up();

        $provenance = DB::table('vacation_availability_migration_provenance')
            ->where('vacation_id', $legacy->id)
            ->firstOrFail();
        $this->assertNotSame((string) $legacy->id, (string) $provenance->override_id);
        $this->assertTrue((bool) $provenance->override_created);
        $this->assertSame('migrated', $legacy->refresh()->status);
        $this->assertDatabaseHas('availability_overrides', [
            'id' => $legacy->id,
            'note' => 'Ongerelateerde bestaande planning.',
        ]);
        $this->assertDatabaseHas('availability_overrides', [
            'id' => $provenance->override_id,
            'starts_at' => today()->addDays(3)->toDateString(),
            'ends_at' => today()->addDays(4)->toDateString(),
            'is_available' => false,
        ]);

        $migration->down();

        $this->assertSame('scheduled', $legacy->refresh()->status);
        $this->assertDatabaseHas('availability_overrides', [
            'id' => $legacy->id,
            'note' => 'Ongerelateerde bestaande planning.',
        ]);
        $this->assertDatabaseMissing('availability_overrides', ['id' => $provenance->override_id]);
    }

    public function test_legacy_migration_refuses_changed_override_rollback_before_any_mutation(): void
    {
        [$firstLegacy, $secondLegacy, $migration] = $this->migratedLegacyPair(
            'vacation-migration-changed@example.test',
        );
        $provenance = DB::table('vacation_availability_migration_provenance')
            ->orderBy('vacation_id')
            ->get();
        $safeOverrideId = (string) $provenance->first()->override_id;
        $changedOverrideId = (string) $provenance->last()->override_id;

        AvailabilityOverride::query()
            ->whereKey($changedOverrideId)
            ->update([
                'is_available' => true,
                'note' => 'Na migratie aangepast.',
            ]);

        try {
            $migration->down();
            $this->fail('Rollback must be refused when a linked override changed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('changed or was deleted', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('vacation_availability_migration_provenance'));
        $this->assertSame('migrated', $firstLegacy->refresh()->status);
        $this->assertSame('migrated', $secondLegacy->refresh()->status);
        $this->assertDatabaseHas('availability_overrides', ['id' => $safeOverrideId]);
        $this->assertDatabaseHas('availability_overrides', [
            'id' => $changedOverrideId,
            'is_available' => true,
            'note' => 'Na migratie aangepast.',
        ]);
    }

    public function test_legacy_migration_refuses_deleted_override_rollback_before_any_mutation(): void
    {
        [$firstLegacy, $secondLegacy, $migration] = $this->migratedLegacyPair(
            'vacation-migration-deleted@example.test',
        );
        $provenance = DB::table('vacation_availability_migration_provenance')
            ->orderBy('vacation_id')
            ->get();
        $safeOverrideId = (string) $provenance->first()->override_id;
        $deletedOverrideId = (string) $provenance->last()->override_id;

        AvailabilityOverride::query()
            ->whereKey($deletedOverrideId)
            ->delete();

        try {
            $migration->down();
            $this->fail('Rollback must be refused when a linked override was deleted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('changed or was deleted', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('vacation_availability_migration_provenance'));
        $this->assertSame('migrated', $firstLegacy->refresh()->status);
        $this->assertSame('migrated', $secondLegacy->refresh()->status);
        $this->assertDatabaseHas('availability_overrides', ['id' => $safeOverrideId]);
        $this->assertDatabaseMissing('availability_overrides', ['id' => $deletedOverrideId]);
    }

    public function test_current_schedule_converts_a_legacy_vacation_status(): void
    {
        $user = $this->user('vacation-status-sync@example.test');
        AvailabilityStatus::query()->create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => 'vacation',
            'is_available' => false,
            'is_system_applied' => true,
            'effective_at' => now()->subMinute(),
        ]);

        $this->asWebClient($user)
            ->postJson('/api/vacations/mine', [
                'starts_at' => today()->toDateString(),
                'ends_at' => today()->addDay()->toDateString(),
                'is_available' => true,
            ])
            ->assertCreated();

        $this->assertSame('available', $this->latestStatus($user)?->status);
    }

    /**
     * @return array{0: UserVacation, 1: UserVacation, 2: Migration}
     */
    private function migratedLegacyPair(string $email): array
    {
        $user = $this->user($email);
        $firstLegacy = UserVacation::query()->create([
            'user_id' => $user->id,
            'starts_at' => today()->addDay(),
            'ends_at' => today()->addDays(2),
            'status' => 'scheduled',
            'created_by' => $user->id,
        ]);
        $secondLegacy = UserVacation::query()->create([
            'user_id' => $user->id,
            'starts_at' => today()->addDays(3),
            'ends_at' => today()->addDays(4),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_07_25_000002_migrate_open_vacations_to_availability_overrides.php');
        $migration->up();

        return [$firstLegacy, $secondLegacy, $migration];
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Vakantiegebruiker',
            'first_name' => 'Vakantie',
            'last_name' => 'Gebruiker',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function grant(User $user, array $permissions): void
    {
        $role = Role::query()->create([
            'name' => 'vacation-test-'.str()->ulid(),
            'display_name' => 'Vakantie testrol',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'category' => 'test',
                    'display_name' => $permissionName,
                    'description' => $permissionName,
                ],
            );
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function override(User $user, string $startsAt, string $endsAt, bool $isAvailable): AvailabilityOverride
    {
        return AvailabilityOverride::query()->create([
            'user_id' => $user->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'day_part' => 'all_day',
            'is_available' => $isAvailable,
            'created_by' => $user->id,
        ]);
    }

    private function latestStatus(User $user): ?AvailabilityStatus
    {
        return $user->statuses()
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken('Vacation API test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
