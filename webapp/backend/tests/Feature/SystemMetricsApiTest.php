<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SystemMetricsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_require_authentication_and_system_health_permission(): void
    {
        $this->getJson('/api/admin/system/metrics')->assertUnauthorized();

        $withoutPermission = $this->user('metrics-denied@example.test');
        $this->asAdminClient($withoutPermission)
            ->getJson('/api/admin/system/metrics')
            ->assertForbidden();
    }

    public function test_metrics_reject_a_session_that_has_not_completed_two_factor_authentication(): void
    {
        $viewer = $this->user('metrics-pending-2fa@example.test', ['system.health.view']);
        $token = $viewer->createToken(
            'Pending metrics admin test',
            ['2fa:pending', 'client:web'],
            now()->addMinutes(10),
        )->plainTextToken;
        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/system/metrics')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');
    }

    public function test_authorized_metrics_response_is_bounded_and_not_cacheable(): void
    {
        config()->set('dis.system_metrics.disk_path', storage_path());
        $viewer = $this->user('metrics-viewer@example.test', ['system.health.view']);

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/system/metrics')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'uptime_seconds',
                    'cpu' => ['usage_percent', 'logical_processors', 'load_average_1m'],
                    'memory' => ['total_bytes', 'used_bytes', 'available_bytes', 'usage_percent'],
                    'disk' => ['label', 'total_bytes', 'used_bytes', 'available_bytes', 'usage_percent'],
                ],
            ])
            ->assertJsonPath('data.disk.label', 'DIS data');

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertStringNotContainsString(storage_path(), (string) $response->getContent());
        $this->assertMetricPercent($payload['cpu']['usage_percent']);
        $this->assertMetricPercent($payload['memory']['usage_percent']);
        $this->assertMetricPercent($payload['disk']['usage_percent']);
    }

    public function test_metrics_polling_has_a_dedicated_per_client_limit(): void
    {
        config()->set('dis.system_metrics.disk_path', storage_path());
        $viewer = $this->user('metrics-limit@example.test', ['system.health.view']);
        $client = $this->asAdminClient($viewer);

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $client->getJson('/api/admin/system/metrics')->assertOk();
        }

        $client->getJson('/api/admin/system/metrics')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'Metrics Test User',
            'first_name' => 'Metrics',
            'last_name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'metrics-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Metrics test role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'Metrics test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Metrics webbeheer test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function assertMetricPercent(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $this->assertIsNumeric($value);
        $this->assertGreaterThanOrEqual(0, (float) $value);
        $this->assertLessThanOrEqual(100, (float) $value);
    }
}
