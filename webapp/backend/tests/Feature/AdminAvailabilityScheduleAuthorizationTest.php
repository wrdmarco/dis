<?php

namespace Tests\Feature;

use App\Models\AvailabilityOverride;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminAvailabilityScheduleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_view_can_read_a_users_full_week_plan_but_other_admins_cannot(): void
    {
        $target = $this->user('week-plan-target@example.test');
        $viewer = $this->user('week-plan-viewer@example.test');
        $otherAdmin = $this->user('week-plan-other-admin@example.test');
        $this->grant($viewer, ['status.view']);
        $this->grant($otherAdmin, ['users.view']);

        $response = $this->asWebClient($viewer)
            ->getJson("/api/availability-statuses/users/{$target->id}/availability-schedule")
            ->assertOk()
            ->assertJsonCount(21, 'data.week_day_parts');

        $dayParts = collect($response->json('data.week_day_parts'));
        foreach (range(1, 7) as $day) {
            $this->assertEqualsCanonicalizing(
                ['morning', 'afternoon', 'evening'],
                $dayParts
                    ->where('day_of_week', $day)
                    ->pluck('day_part')
                    ->all(),
            );
        }

        $this->asWebClient($otherAdmin)
            ->getJson("/api/availability-statuses/users/{$target->id}/availability-schedule")
            ->assertForbidden();
    }

    public function test_status_view_cannot_change_a_users_week_plan_and_status_override_can(): void
    {
        $target = $this->user('week-plan-write-target@example.test');
        $viewer = $this->user('week-plan-write-viewer@example.test');
        $manager = $this->user('week-plan-manager@example.test');
        $this->grant($viewer, ['status.view']);
        $this->grant($manager, ['status.override']);

        $patterns = collect(range(1, 7))
            ->map(fn (int $day): array => [
                'day_of_week' => $day,
                'day_part' => 'all_day',
                'is_available' => $day !== 7,
            ])
            ->all();
        $override = [
            'starts_at' => today()->addDay()->toDateString(),
            'ends_at' => today()->addDay()->toDateString(),
            'day_part' => 'morning',
            'is_available' => false,
        ];
        $patternUrl = "/api/availability-statuses/users/{$target->id}/availability-schedule/week-pattern";
        $overrideUrl = "/api/availability-statuses/users/{$target->id}/availability-schedule/overrides";

        $this->asWebClient($viewer)
            ->patchJson($patternUrl, ['patterns' => $patterns])
            ->assertForbidden();
        $this->asWebClient($viewer)
            ->postJson($overrideUrl, $override)
            ->assertForbidden();

        $this->asWebClient($manager)
            ->patchJson($patternUrl, ['patterns' => $patterns])
            ->assertOk();
        $this->asWebClient($manager)
            ->postJson($overrideUrl, $override)
            ->assertCreated();

        $storedOverride = AvailabilityOverride::query()
            ->where('user_id', $target->id)
            ->sole();

        config()->set('app.timezone', 'Europe/Amsterdam');
        $this->asWebClient($viewer)
            ->getJson("/api/availability-statuses/users/{$target->id}/availability-schedule")
            ->assertOk()
            ->assertJsonPath(
                'data.overrides.0.updated_at',
                ApiDateTime::dateTime($storedOverride->updated_at),
            );
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Weekplanning gebruiker',
            'first_name' => 'Weekplanning',
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
     * @param  list<string>  $permissionNames
     */
    private function grant(User $user, array $permissionNames): void
    {
        $role = Role::query()->create([
            'name' => 'week-plan-test-'.strtolower((string) str()->ulid()),
            'display_name' => 'Weekplanning testrol',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'category' => 'test',
                    'display_name' => $permissionName,
                    'description' => $permissionName,
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }

        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken('Week planning API test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
