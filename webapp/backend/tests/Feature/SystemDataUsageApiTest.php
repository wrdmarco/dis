<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SystemDataUsageApiTest extends TestCase
{
    use RefreshDatabase;

    private string $snapshotRoot;

    private string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03T12:00:00Z'));
        $suffix = bin2hex(random_bytes(8));
        $this->snapshotRoot = storage_path('framework/testing/system-data-usage-'.$suffix);
        $this->snapshotPath = $this->snapshotRoot.'/storage-usage.json';
        File::ensureDirectoryExists($this->snapshotRoot, 0700, true);
        config()->set('dis.system_metrics.data_usage.snapshot_path', $this->snapshotPath);
        config()->set('dis.system_metrics.data_usage.stale_after_seconds', 10_800);
        $this->writeSnapshot([
            'storage' => 2_000,
            'backup' => 4_000,
            'secrets' => 50,
            '../../sensitive/customer-document.txt' => 99_999,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        File::deleteDirectory($this->snapshotRoot);

        parent::tearDown();
    }

    public function test_data_usage_requires_authentication_completed_two_factor_web_client_and_health_permission(): void
    {
        $this->getJson('/api/admin/system/data-usage')->assertUnauthorized();

        $withoutPermission = $this->user('data-usage-denied@example.test');
        $this->asAdminClient($withoutPermission)
            ->getJson('/api/admin/system/data-usage')
            ->assertForbidden();

        $viewer = $this->user('data-usage-pending-2fa@example.test', ['system.health.view']);
        $pendingToken = $viewer->createToken(
            'Pending data usage admin test',
            ['2fa:pending', 'client:web'],
            now()->addMinutes(10),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$pendingToken)
            ->getJson('/api/admin/system/data-usage')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $nativeToken = $viewer->createToken(
            'Native client cannot read data usage',
            ['*', 'client:operator'],
            now()->addMinutes(10),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$nativeToken)
            ->getJson('/api/admin/system/data-usage')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'web_client_required');
    }

    public function test_authorized_response_is_allowlisted_sorted_and_does_not_expose_paths_or_snapshot_fields(): void
    {
        $viewer = $this->user('data-usage-viewer@example.test', ['system.health.view']);

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/system/data-usage')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.generated_at', '2026-08-03T11:59:00Z')
            ->assertJsonPath('data.stale', false)
            ->assertJsonCount(2, 'data.directories')
            ->assertJsonPath('data.directories.0.name', 'backup')
            ->assertJsonPath('data.directories.0.label', 'backup')
            ->assertJsonPath('data.directories.0.size_bytes', 4_000)
            ->assertJsonPath('data.directories.1.name', 'storage')
            ->assertJsonPath('data.directories.1.label', 'storage')
            ->assertJsonPath('data.directories.1.size_bytes', 2_000);

        $serialized = (string) $response->getContent();
        $this->assertStringNotContainsString($this->snapshotRoot, $serialized);
        $this->assertStringNotContainsString($this->snapshotPath, $serialized);
        $this->assertStringNotContainsString('customer-document.txt', $serialized);
        $this->assertStringNotContainsString('sensitive', $serialized);
        $this->assertStringNotContainsString('secrets', $serialized);
        $this->assertStringNotContainsString('version', $serialized);
    }

    public function test_missing_or_invalid_snapshot_returns_only_a_generic_stale_empty_state(): void
    {
        File::put($this->snapshotPath, '{"private_path":"/opt/dis-data/secrets/private.key"}');
        chmod($this->snapshotPath, 0640);
        $viewer = $this->user('data-usage-unavailable@example.test', ['system.health.view']);

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/system/data-usage')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'data' => [
                    'generated_at' => null,
                    'stale' => true,
                    'directories' => [],
                ],
            ]);

        $this->assertStringNotContainsString('/opt/dis-data', (string) $response->getContent());
        $this->assertStringNotContainsString('private.key', (string) $response->getContent());
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'Data Usage Test User',
            'first_name' => 'Data',
            'last_name' => 'Usage Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'data-usage-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Data usage test role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => true,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'Data usage test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Data usage webbeheer test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @param array<string, int> $directories */
    private function writeSnapshot(array $directories): void
    {
        File::put($this->snapshotPath, json_encode([
            'version' => 1,
            'generated_at' => '2026-08-03T11:59:00Z',
            'directories' => $directories,
        ], JSON_THROW_ON_ERROR));
        chmod($this->snapshotPath, 0640);
    }
}
