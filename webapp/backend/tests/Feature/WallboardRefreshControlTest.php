<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardSession;
use App\Services\WallboardSessionService;
use App\Support\WallboardConfiguration;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WallboardRefreshControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_persist_monotone_refresh_commands_without_revoking_the_kiosk_session(): void
    {
        $manager = $this->user('wallboard-refresh-manager@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager);
        [$session, $cookie] = $this->wallboardCredential($wallboard);
        $client = $this->asAdminClient($manager);

        $client->getJson('/api/admin/wallboards/'.$wallboard->id)
            ->assertOk()
            ->assertJsonPath('data.control_version', 1)
            ->assertJsonPath('data.refresh_version', 0);

        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/refresh', [
            'expected_control_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.control_version', 2)
            ->assertJsonPath('data.refresh_version', 1);

        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/refresh', [
            'expected_control_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.control_version', 3)
            ->assertJsonPath('data.refresh_version', 2);

        $wallboard->refresh();
        $this->assertSame(3, $wallboard->control_version);
        $this->assertSame(2, $wallboard->refresh_version);
        $this->assertNull($session->refresh()->revoked_at);

        $this->wallboardGet('/api/wallboard/state', $cookie)
            ->assertOk()
            ->assertJsonPath('data.wallboard.control_version', 3)
            ->assertJsonPath('data.wallboard.refresh_version', 2);
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertJsonPath('data.control_version', 3)
            ->assertJsonPath('data.refresh_version', 2);

        $audits = AuditLog::query()
            ->where('action', 'wallboards.refresh_commanded')
            ->where('target_id', $wallboard->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $audits);
        $this->assertSame([1, 2], $audits->pluck('metadata.refresh_version')->all());
        $this->assertSame([2, 3], $audits->pluck('metadata.control_version')->all());
        $this->assertSame($manager->id, $audits->last()?->actor_id);
    }

    public function test_refresh_command_requires_authentication_permission_completed_two_factor_and_current_version(): void
    {
        $manager = $this->user('wallboard-refresh-owner@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager);

        $this->postJson('/api/admin/wallboards/'.$wallboard->id.'/refresh', [
            'expected_control_version' => 1,
        ])->assertUnauthorized();

        $unprivileged = $this->user('wallboard-refresh-denied@example.test', []);
        $this->asAdminClient($unprivileged)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/refresh', [
                'expected_control_version' => 1,
            ])->assertForbidden();

        $pendingToken = $manager->createToken(
            'Pending wallboard refresh',
            ['2fa:pending', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($pendingToken)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/refresh', [
                'expected_control_version' => 1,
            ])->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $client = $this->asAdminClient($manager);
        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/refresh', [
            'expected_control_version' => 1,
        ])->assertOk();

        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/refresh', [
            'expected_control_version' => 1,
        ])->assertStatus(409);
        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/refresh', [])
            ->assertUnprocessable();

        $wallboard->refresh();
        $this->assertSame(2, $wallboard->control_version);
        $this->assertSame(1, $wallboard->refresh_version);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'wallboards.refresh_commanded')
            ->where('target_id', $wallboard->id)
            ->count());
    }

    public function test_disabled_wallboard_cannot_receive_a_refresh_command(): void
    {
        $manager = $this->user('wallboard-refresh-disabled@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager);
        $wallboard->forceFill(['is_enabled' => false])->save();

        $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/refresh', [
                'expected_control_version' => 1,
            ])->assertUnprocessable();

        $wallboard->refresh();
        $this->assertSame(1, $wallboard->control_version);
        $this->assertSame(0, $wallboard->refresh_version);
    }

    private function wallboard(User $actor): Wallboard
    {
        return Wallboard::query()->create([
            'name' => 'Herstartbaar wallboard',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::normalize([
                'pages' => [
                    [
                        'id' => 'map',
                        'name' => 'Kaart',
                        'type' => 'map',
                        'duration_seconds' => 30,
                        'options' => [],
                    ],
                ],
                'incident_override' => ['enabled' => false, 'page_id' => 'map'],
            ]),
            'is_enabled' => true,
            'rotation_started_at' => now(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @return array{0: WallboardSession, 1: string} */
    private function wallboardCredential(Wallboard $wallboard): array
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash_hmac('sha256', $secret, (string) config('app.key')),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => null,
        ]);

        return [$session, $session->id.'.'.$secret];
    }

    private function wallboardGet(string $uri, string $cookie): TestResponse
    {
        Auth::forgetGuards();
        $this->withoutMiddleware(EncryptCookies::class);

        return $this->disableCookieEncryption()
            ->withUnencryptedCookie(WallboardSessionService::COOKIE_NAME, $cookie)
            ->withCredentials()
            ->withHeaders(['Origin' => 'https://dis.example.test'])
            ->getJson($uri);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'Wallboard Refresh User',
            'first_name' => 'Wallboard',
            'last_name' => 'Refresh User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-refresh-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Wallboard refresh role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'Test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Wallboard refresh test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
