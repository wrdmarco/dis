<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardSession;
use App\Services\WallboardMaintenanceNoticeService;
use App\Services\WallboardSessionService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WallboardMaintenanceNoticeApiTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dis-wallboard-api-maintenance-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->directory, 0700));
        $this->path = $this->directory.DIRECTORY_SEPARATOR.'wallboard-status.json';
        $this->app->instance(
            WallboardMaintenanceNoticeService::class,
            new WallboardMaintenanceNoticeService($this->path, false),
        );
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        if (is_file($this->path) || is_link($this->path)) {
            unlink($this->path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_state_and_control_publish_the_same_maintenance_contract_and_expire_it(): void
    {
        file_put_contents($this->path, json_encode([
            'version' => 2,
            'active' => true,
            'kind' => 'update',
            'started_at' => '2026-07-19T10:00:00Z',
            'estimated_duration_seconds' => 900,
            'estimated_completion_at' => '2026-07-19T10:15:00Z',
            'expires_at' => '2026-07-19T16:00:00Z',
        ], JSON_THROW_ON_ERROR));

        [$wallboard, $cookie] = $this->wallboardCredential();
        foreach (['/api/wallboard/state', '/api/wallboard/control'] as $uri) {
            $this->wallboardGet($uri, $cookie)
                ->assertOk()
                ->assertJsonPath('data.maintenance.active', true)
                ->assertJsonPath('data.maintenance.kind', 'update')
                ->assertJsonPath('data.maintenance.title', 'Systeem wordt bijgewerkt')
                ->assertJsonPath('data.maintenance.started_at', '2026-07-19T10:00:00Z')
                ->assertJsonPath('data.maintenance.estimated_duration_seconds', 900)
                ->assertJsonPath('data.maintenance.estimated_completion_at', '2026-07-19T10:15:00Z')
                ->assertJsonPath('data.maintenance.remaining_seconds', 900)
                ->assertJsonPath('data.maintenance.expires_at', '2026-07-19T16:00:00Z');
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19T16:00:00Z'));
        $this->wallboardGet('/api/wallboard/state', $cookie)
            ->assertOk()
            ->assertJsonPath('data.maintenance', null);
        $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertJsonPath('data.maintenance', null);

        $this->assertNull($wallboard->sessions()->firstOrFail()->expires_at);
    }

    /** @return array{0: Wallboard, 1: string} */
    private function wallboardCredential(): array
    {
        $user = User::query()->create([
            'name' => 'Wallboard Maintenance User',
            'first_name' => 'Wallboard',
            'last_name' => 'Maintenance User',
            'email' => 'wallboard-maintenance@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $wallboard = Wallboard::query()->create([
            'name' => 'Onderhoudswallboard',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::normalize([
                'pages' => [[
                    'id' => 'map',
                    'name' => 'Kaart',
                    'type' => 'map',
                    'duration_seconds' => 30,
                    'options' => [],
                ]],
                'incident_override' => ['enabled' => false, 'page_id' => 'map'],
            ]),
            'is_enabled' => true,
            'rotation_started_at' => now(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash_hmac('sha256', $secret, (string) config('app.key')),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => null,
        ]);

        return [$wallboard, $session->id.'.'.$secret];
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
}
