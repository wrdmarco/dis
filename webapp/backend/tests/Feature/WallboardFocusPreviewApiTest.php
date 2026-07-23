<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DispatchRequest;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WallboardFocusPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_focus_test_requires_wallboard_management_completed_two_factor_and_strict_input(): void
    {
        $manager = $this->user('focus-preview-manager@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager, 'Beveiligd scherm');

        $this->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
            'kind' => 'real_alarm',
            'expected_control_version' => 1,
        ])->assertUnauthorized();

        $unprivileged = $this->user('focus-preview-denied@example.test', []);
        $this->asAdminClient($unprivileged)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
                'kind' => 'real_alarm',
                'expected_control_version' => 1,
            ])->assertForbidden();

        $pendingToken = $manager->createToken(
            'Focus preview pending 2FA',
            ['2fa:pending', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($pendingToken)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
                'kind' => 'real_alarm',
                'expected_control_version' => 1,
            ])->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $client = $this->asAdminClient($manager);
        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
            'kind' => 'unknown',
            'expected_control_version' => 1,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['kind']]]);
        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
            'kind' => 'test_alarm',
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['expected_control_version']]]);

        $wallboard->forceFill(['is_enabled' => false])->save();
        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
            'kind' => 'test_alarm',
            'expected_control_version' => 1,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['wallboard']]]);
    }

    public function test_each_mock_focus_is_screen_scoped_deterministic_and_restores_the_existing_playlist(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 16:00:00', 'Europe/Amsterdam'));
        $manager = $this->user('focus-preview-kinds@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager, 'Testscherm');
        $other = $this->wallboard($manager, 'Ander scherm');
        $wallboard->forceFill([
            'manual_page_id' => 'summary',
            'manual_page_set_at' => now()->subMinute(),
            'rotation_started_at' => now()->subHours(2),
        ])->save();
        $storedRotation = DB::table('wallboards')->where('id', $wallboard->id)->value('rotation_started_at');
        $cookie = $this->wallboardCredential($wallboard);
        $otherCookie = $this->wallboardCredential($other);
        $client = $this->asAdminClient($manager);

        $expectedControlVersion = 1;
        foreach (['preannouncement', 'test_alarm', 'real_alarm'] as $kind) {
            $preview = $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
                'kind' => $kind,
                'expected_control_version' => $expectedControlVersion,
            ])->assertOk()
                ->assertJsonPath('data.kind', $kind)
                ->assertJsonPath('data.is_preview', true)
                ->assertJsonPath('data.duration_seconds', 30)
                ->assertJsonPath('data.control_version', $expectedControlVersion + 1);
            $expectedControlVersion++;

            $runtime = $this->wallboardGet('/api/wallboard/control', $cookie)
                ->assertOk()
                ->assertJsonPath('data.focus.kind', $kind)
                ->assertJsonPath('data.focus.is_preview', true)
                ->assertJsonPath('data.focus.focus_id', $preview->json('data.focus_id'))
                ->assertJsonPath('data.focus.visible', true)
                ->assertJsonPath('data.focus.expires_at', '2026-07-20T16:00:30+02:00')
                ->assertJsonPath('data.display.mode', 'manual')
                ->assertJsonPath('data.display.page_id', 'summary');

            $focus = $runtime->json('data.focus');
            $this->assertStringContainsString('geen echte inzet', mb_strtolower((string) $focus['title']));
            if ($kind !== 'real_alarm') {
                $this->assertSame([], $focus['responses']['coming'] ?? null, $kind);
            }
            if ($kind === 'preannouncement') {
                $this->assertSame([
                    'available' => 7,
                    'relevant' => 12,
                    'contacted' => 12,
                ], $focus['pilot_counts']);
            }
            if ($kind === 'test_alarm') {
                $this->assertSame(18, $focus['responses']['counts']['contacted']);
                $this->assertSame(15, $focus['responses']['counts']['accepted']);
            }
            if ($kind === 'real_alarm') {
                $this->assertSame(12, $focus['responses']['counts']['contacted']);
                $this->assertSame(5, $focus['responses']['counts']['accepted']);
                $this->assertCount(5, $focus['responses']['coming']);
                $this->assertSame([6, 11, 17, 24, null], array_column($focus['responses']['coming'], 'eta_minutes'));
                $this->assertSame(
                    ['navigation', 'navigation', 'fallback', 'fallback', null],
                    array_column($focus['responses']['coming'], 'eta_source'),
                );
            }
        }

        $this->wallboardGet('/api/wallboard/control', $otherCookie)
            ->assertOk()
            ->assertJsonPath('data.focus', null);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertSame('summary', $wallboard->refresh()->manual_page_id);
        $this->assertSame(
            $storedRotation,
            DB::table('wallboards')->where('id', $wallboard->id)->value('rotation_started_at'),
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 16:00:30', 'Europe/Amsterdam'));
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertJsonPath('data.focus', null)
            ->assertJsonPath('data.display.mode', 'manual')
            ->assertJsonPath('data.display.page_id', 'summary');
    }

    public function test_real_alarm_blocks_start_and_takes_priority_if_it_begins_during_a_preview(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 17:00:00', 'Europe/Amsterdam'));
        $manager = $this->user('focus-preview-priority@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager, 'Prioriteitsscherm');
        $cookie = $this->wallboardCredential($wallboard);
        $client = $this->asAdminClient($manager);

        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
            'kind' => 'test_alarm',
            'expected_control_version' => 1,
        ])->assertOk();

        $incident = Incident::query()->create([
            'reference' => 'FOCUS-PREVIEW-REAL',
            'title' => 'Werkelijk incident',
            'priority' => 'high',
            'status' => 'dispatching',
            'is_test' => false,
            'location_label' => 'Utrecht',
            'created_by' => $manager->id,
            'created_by_name' => $manager->name,
            'created_by_email' => $manager->email,
            'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $manager->id,
            'requested_by_name' => $manager->name,
            'requested_by_email' => $manager->email,
            'status' => 'sent',
            'priority' => 'high',
            'message' => 'Werkelijk alarm',
            'sent_at' => now(),
        ]);

        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertJsonPath('data.focus.kind', 'real_alarm')
            ->assertJsonPath('data.focus.is_preview', false)
            ->assertJsonPath('data.focus.incident_id', $incident->id)
            ->assertJsonPath('data.focus.dispatch_id', $dispatch->id);

        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
            'kind' => 'preannouncement',
            'expected_control_version' => 2,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['wallboard']]]);
    }

    public function test_success_is_domain_audited_and_repeated_per_screen_tests_are_rate_limited(): void
    {
        $manager = $this->user('focus-preview-audit@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard($manager, 'Rate-limitscherm');
        $client = $this->asAdminClient($manager);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
                'kind' => 'real_alarm',
                'expected_control_version' => $attempt + 1,
            ])->assertOk();
        }

        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/focus-test', [
            'kind' => 'real_alarm',
            'expected_control_version' => 7,
        ])->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $audits = AuditLog::query()
            ->where('action', 'wallboards.focus_preview_started')
            ->where('target_id', $wallboard->id)
            ->get();
        $this->assertCount(6, $audits);
        $this->assertSame('real_alarm', $audits->last()->metadata['kind']);
        $this->assertSame(30, $audits->last()->metadata['duration_seconds']);
        $this->assertSame(7, $audits->last()->metadata['control_version']);
    }

    private function wallboard(User $actor, string $name): Wallboard
    {
        return Wallboard::query()->create([
            'name' => $name,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::normalize([
                'rotation_enabled' => true,
                'pages' => [
                    ['id' => 'map', 'name' => 'Kaart', 'type' => 'map', 'duration_seconds' => 10, 'options' => []],
                    ['id' => 'summary', 'name' => 'Samenvatting', 'type' => 'summary', 'duration_seconds' => 10, 'options' => []],
                ],
            ]),
            'is_enabled' => true,
            'rotation_started_at' => now(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function wallboardCredential(Wallboard $wallboard): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash_hmac('sha256', $secret, (string) config('app.key')),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        return $session->id.'.'.$secret;
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
            'name' => 'Wallboard Focus Preview User',
            'first_name' => 'Wallboard',
            'last_name' => 'Focus Preview User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-focus-preview-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Wallboard focus preview role',
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
        $token = $user->createToken('Wallboard focus preview test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
