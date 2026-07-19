<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardSession;
use App\Services\WallboardSessionService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WallboardRuntimeControlTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_pages_are_strictly_validated_and_stale_admin_commands_cannot_overwrite_newer_control(): void
    {
        $manager = $this->user('wallboard-control@example.test', ['wallboards.manage']);
        $client = $this->asAdminClient($manager);
        $wallboard = $this->wallboard($manager, $this->configuration());

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 1,
            'configuration' => [
                'pages' => [
                    ['id' => 'map', 'name' => 'Kaart', 'type' => 'map', 'duration_seconds' => 15, 'options' => []],
                    ['id' => 'message', 'name' => 'Mededeling', 'type' => 'message', 'duration_seconds' => 20, 'options' => ['body' => 'Start briefing om 14:00.']],
                    ['id' => 'incidents', 'name' => 'Incidenten', 'type' => 'incident_list', 'duration_seconds' => 10, 'options' => ['show_test_incidents' => false]],
                ],
                'incident_override' => ['enabled' => true, 'page_id' => 'incidents'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.config_version', 2)
            ->assertJsonPath('data.control_version', 2)
            ->assertJsonPath('data.configuration.pages.1.options.body', 'Start briefing om 14:00.');

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 1,
            'name' => 'Stale wijziging',
        ])->assertStatus(409);

        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
            'page_id' => 'message',
            'expected_control_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.control_version', 3)
            ->assertJsonPath('data.display.mode', 'manual')
            ->assertJsonPath('data.display.page_id', 'message');

        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
            'page_id' => 'map',
            'expected_control_version' => 2,
        ])->assertStatus(409);

        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
            'page_id' => 'missing',
            'expected_control_version' => 3,
        ])->assertUnprocessable();

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 2,
            'configuration' => [
                'pages' => [
                    ['id' => 'map', 'name' => 'Kaart', 'type' => 'map', 'duration_seconds' => 15, 'options' => []],
                ],
                'incident_override' => ['enabled' => false, 'page_id' => 'map'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.display.mode', 'static')
            ->assertJsonPath('data.display.page_id', 'map');
        $this->assertNull($wallboard->refresh()->manual_page_id);

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 3,
            'configuration' => [
                'pages' => [
                    ['id' => 'unsafe', 'name' => 'Onveilig', 'type' => 'message', 'duration_seconds' => 10, 'options' => ['body' => '<script>alert(1)</script>']],
                ],
            ],
        ])->assertUnprocessable();

        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboards.display_commanded')
            ->where('target_id', $wallboard->id)
            ->exists());
    }

    public function test_runtime_control_requires_rbac_completed_two_factor_and_an_enabled_wallboard(): void
    {
        $manager = $this->user('wallboard-control-manager@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager, $this->configuration());

        $unprivileged = $this->user('wallboard-control-denied@example.test', []);
        $this->asAdminClient($unprivileged)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
                'page_id' => 'map',
                'expected_control_version' => 1,
            ])->assertForbidden();

        $pending = $manager->createToken('Pending wallboard control', ['2fa:pending', 'client:admin'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($pending)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
                'page_id' => 'map',
                'expected_control_version' => 1,
            ])->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $wallboard->forceFill(['is_enabled' => false])->save();
        $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
                'page_id' => 'map',
                'expected_control_version' => 1,
            ])->assertUnprocessable();
    }

    public function test_rotation_is_server_authoritative_and_resume_reanchors_the_cycle(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 10:00:00', 'Europe/Amsterdam'));
        $manager = $this->user('wallboard-rotation@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager, $this->configuration());
        [, $cookie] = $this->wallboardCredential($wallboard);

        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.display.mode', 'rotation')
            ->assertJsonPath('data.display.page_id', 'map')
            ->assertJsonPath('data.display.next_change_at', '2026-07-19T10:00:05+02:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 10:00:05', 'Europe/Amsterdam'));
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertJsonPath('data.display.page_id', 'summary')
            ->assertJsonPath('data.display.next_change_at', '2026-07-19T10:00:15+02:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 10:00:06', 'Europe/Amsterdam'));
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertJsonPath('data.display.page_id', 'summary')
            ->assertJsonPath('data.display.next_change_at', '2026-07-19T10:00:15+02:00');

        $client = $this->asAdminClient($manager);
        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
            'page_id' => 'summary',
            'expected_control_version' => 1,
        ])->assertOk()->assertJsonPath('data.display.mode', 'manual');
        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
            'page_id' => null,
            'expected_control_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.display.mode', 'rotation')
            ->assertJsonPath('data.display.page_id', 'map')
            ->assertJsonPath('data.display.next_change_at', '2026-07-19T10:00:11+02:00');
    }

    public function test_a_future_rotation_anchor_falls_back_to_the_current_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 11:00:00', 'Europe/Amsterdam'));
        $manager = $this->user('wallboard-future-anchor@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager, $this->configuration());
        $wallboard->forceFill(['rotation_started_at' => now()->addHour()])->save();
        [, $cookie] = $this->wallboardCredential($wallboard);

        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertJsonPath('data.display.mode', 'rotation')
            ->assertJsonPath('data.display.page_id', 'map')
            ->assertJsonPath('data.display.next_change_at', '2026-07-19T11:00:05+02:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 11:00:05', 'Europe/Amsterdam'));
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertJsonPath('data.display.page_id', 'summary')
            ->assertJsonPath('data.display.next_change_at', '2026-07-19T11:00:15+02:00');
    }

    public function test_only_real_dispatched_incidents_override_manual_display_until_the_last_incident_ends(): void
    {
        $manager = $this->user('wallboard-override@example.test', ['wallboards.manage']);
        $configuration = $this->configuration();
        $configuration['incident_override'] = ['enabled' => true, 'page_id' => 'summary'];
        $wallboard = $this->wallboard($manager, $configuration);
        [, $cookie] = $this->wallboardCredential($wallboard);

        $this->asAdminClient($manager)->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
            'page_id' => 'map',
            'expected_control_version' => 1,
        ])->assertOk();

        $openOnly = $this->incident($manager, 'active', false);
        $testDispatch = $this->incident($manager, 'dispatching', true);
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertJsonPath('data.display.mode', 'manual')
            ->assertJsonPath('data.display.incident_active', false);

        $first = $this->incident($manager, 'dispatching', false);
        $second = $this->incident($manager, 'in_progress', false);
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertJsonPath('data.display.mode', 'incident_override')
            ->assertJsonPath('data.display.page_id', 'summary')
            ->assertJsonPath('data.display.incident_active', true);

        $client = $this->asAdminClient($manager);
        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/display', [
            'page_id' => 'map',
            'expected_control_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.control_version', 3)
            ->assertJsonPath('data.display.mode', 'incident_override')
            ->assertJsonPath('data.display.page_id', 'summary');

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 1,
            'configuration' => [
                'incident_override' => ['enabled' => true, 'page_id' => 'map'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.config_version', 2)
            ->assertJsonPath('data.control_version', 4)
            ->assertJsonPath('data.display.mode', 'incident_override')
            ->assertJsonPath('data.display.page_id', 'map');

        $first->forceFill(['status' => 'resolved', 'closed_at' => now()])->save();
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertJsonPath('data.display.mode', 'incident_override')
            ->assertJsonPath('data.display.page_id', 'map');

        $second->forceFill(['status' => 'cancelled', 'closed_at' => now()])->save();
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertJsonPath('data.display.mode', 'manual')
            ->assertJsonPath('data.display.page_id', 'map')
            ->assertJsonPath('data.display.incident_active', false);

        $openOnly->delete();
        $testDispatch->delete();
    }

    public function test_lightweight_control_poll_is_cookie_authenticated_and_rate_limited(): void
    {
        $manager = $this->user('wallboard-poll@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager, $this->configuration());
        [, $cookie] = $this->wallboardCredential($wallboard);

        $this->getJson('/api/wallboard/control')->assertUnauthorized();

        $successfulPolls = 0;
        $limited = null;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $response = $this->wallboardGet('/api/wallboard/control', $cookie);
            if ($response->getStatusCode() === 429) {
                $limited = $response;
                break;
            }

            $response->assertOk();
            $successfulPolls++;
        }
        $this->assertGreaterThanOrEqual(30, $successfulPolls);
        $this->assertNotNull($limited);
        $limited
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_incident_pages_receive_incidents_when_the_map_incident_layer_is_disabled(): void
    {
        $manager = $this->user('wallboard-list-feed@example.test', ['wallboards.manage']);
        $configuration = $this->configuration();
        $configuration['map']['show_active_incidents'] = false;
        $configuration['pages'] = [
            ['id' => 'incidents', 'name' => 'Incidenten', 'type' => 'incident_list', 'duration_seconds' => 10, 'options' => ['show_test_incidents' => false]],
        ];
        $configuration['incident_override'] = ['enabled' => false, 'page_id' => 'incidents'];
        $wallboard = $this->wallboard($manager, WallboardConfiguration::normalize($configuration));
        [, $cookie] = $this->wallboardCredential($wallboard);
        $incident = $this->incident($manager, 'dispatching', false);

        $this->wallboardGet('/api/wallboard/state', $cookie)
            ->assertOk()
            ->assertJsonCount(1, 'data.map.incidents')
            ->assertJsonPath('data.map.incidents.0.id', $incident->id);
    }

    public function test_message_only_wallboard_does_not_receive_unused_operational_map_data(): void
    {
        $manager = $this->user('wallboard-message-feed@example.test', ['wallboards.manage']);
        $configuration = WallboardConfiguration::normalize([
            'pages' => [
                ['id' => 'message', 'name' => 'Mededeling', 'type' => 'message', 'duration_seconds' => 30, 'options' => ['body' => 'Stand-by.']],
            ],
            'incident_override' => ['enabled' => false, 'page_id' => 'message'],
        ]);
        $wallboard = $this->wallboard($manager, $configuration);
        [, $cookie] = $this->wallboardCredential($wallboard);
        $this->incident($manager, 'dispatching', false);

        $this->wallboardGet('/api/wallboard/state', $cookie)
            ->assertOk()
            ->assertJsonCount(0, 'data.map.incidents')
            ->assertJsonCount(0, 'data.map.command_centers')
            ->assertJsonCount(0, 'data.map.historical_incidents')
            ->assertJsonCount(0, 'data.map.live_locations');
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        return WallboardConfiguration::normalize([
            'rotation_enabled' => true,
            'pages' => [
                ['id' => 'map', 'name' => 'Kaart', 'type' => 'map', 'duration_seconds' => 5, 'options' => []],
                ['id' => 'summary', 'name' => 'Samenvatting', 'type' => 'summary', 'duration_seconds' => 10, 'options' => ['show_test_incidents' => false]],
            ],
            'incident_override' => ['enabled' => false, 'page_id' => 'summary'],
            'map' => [
                'show_active_incidents' => false,
                'show_live_locations' => false,
                'show_routes' => false,
                'show_command_centers' => false,
                'show_historical_incidents' => false,
            ],
        ]);
    }

    /** @param array<string, mixed> $configuration */
    private function wallboard(User $actor, array $configuration): Wallboard
    {
        return Wallboard::query()->create([
            'name' => 'Bestuurbaar wallboard',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => $configuration,
            'is_enabled' => true,
            'rotation_started_at' => now(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function incident(User $creator, string $status, bool $isTest): Incident
    {
        return Incident::query()->create([
            'reference' => 'WB-'.str()->upper((string) str()->random(8)),
            'title' => 'Wallboardincident',
            'priority' => 'normal',
            'status' => $status,
            'is_test' => $isTest,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
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
            'expires_at' => now()->addDays(30),
        ]);
        $credential = $session->id.'.'.$secret;

        return [$session, $credential];
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
            'name' => 'Wallboard Control User',
            'first_name' => 'Wallboard',
            'last_name' => 'Control User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-control-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Wallboard control role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['display_name' => $permissionName, 'category' => 'system_configuration', 'description' => 'Test permission'],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Wallboard control test', ['*', 'client:admin'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
