<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Models\WallboardSession;
use App\Services\WallboardLiveStreamDeliveryService;
use App\Services\WallboardSessionService;
use App\Services\WebSessionService;
use App\Support\WallboardConfiguration;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WallboardLiveStreamDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const STREAM_KEY = 'Only_Server_Knows_This~Secure_Code_42';

    private string $runtimeDirectory;

    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeDirectory = storage_path('framework/testing/dis-wallboard-live-delivery-'.Str::lower((string) Str::ulid()));
        $this->outputDirectory = $this->runtimeDirectory.DIRECTORY_SEPARATOR.'hls';
        config()->set('wallboard_live_stream', [
            'enabled' => true,
            'public_host' => 'stream.example.test',
            'rtmps_bind_address' => '0.0.0.0',
            'rtmps_port' => 1936,
            'stream_key' => self::STREAM_KEY,
            'tls_certificate_path' => '/etc/letsencrypt/live/stream.example.test/fullchain.pem',
            'tls_private_key_path' => '/etc/letsencrypt/live/stream.example.test/privkey.pem',
            'runtime_directory' => $this->runtimeDirectory,
            'output_directory' => $this->outputDirectory,
            'segment_duration_seconds' => 2,
            'segment_list_size' => 6,
            'manifest_stale_seconds' => 12,
            'max_manifest_bytes' => 64 * 1024,
            'max_segment_bytes' => 6 * 1024 * 1024,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDirectory)) {
            foreach ((array) scandir($this->outputDirectory) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->outputDirectory.DIRECTORY_SEPARATOR.$entry);
                }
            }
            @rmdir($this->outputDirectory);
        }
        if (is_dir($this->runtimeDirectory)) {
            @rmdir($this->runtimeDirectory);
        }

        parent::tearDown();
    }

    public function test_admin_status_is_permission_protected_server_authoritative_and_never_exposes_stream_key(): void
    {
        $this->getJson('/api/admin/wallboard-live-stream/status')->assertUnauthorized();

        $unprivileged = $this->user('live-stream-denied@example.test');
        $this->asAdminClient($unprivileged)
            ->getJson('/api/admin/wallboard-live-stream/status')
            ->assertForbidden();

        $manager = $this->user('live-stream-manager@example.test', ['wallboards.manage']);
        $response = $this->asAdminClient($manager)
            ->withHeader('Host', 'attacker.example.test')
            ->getJson('/api/admin/wallboard-live-stream/status')
            ->assertOk()
            ->assertExactJson(['data' => [
                'status' => 'waiting',
                'server_url' => 'rtmps://stream.example.test:1936/live',
                'stream_key_configured' => true,
                'stream_key_version' => null,
                'last_packet_at' => null,
                'message' => 'Wachten op een geldig OBS-signaal.',
            ]]);

        $encoded = (string) $response->getContent();
        $this->assertStringNotContainsString(self::STREAM_KEY, $encoded);
        $this->assertStringNotContainsString('attacker.example.test', $encoded);
    }

    public function test_admin_stream_reads_validate_browser_session_without_renewing_idle_activity(): void
    {
        $manager = $this->user('live-stream-passive-session@example.test', ['wallboards.manage']);
        $lastActivityAt = now()->subSeconds(30)->getTimestamp();

        $this->asWebSession($manager, $lastActivityAt)
            ->getJson('/api/admin/wallboard-live-stream/status')
            ->assertOk()
            ->assertSessionHas(WebSessionService::KEY_LAST_ACTIVITY_AT, $lastActivityAt);

        config()->set('session.lifetime', 1);
        $expiredAt = now()->subSeconds(61)->getTimestamp();
        $this->asWebSession($manager, $expiredAt)
            ->getJson('/api/admin/wallboard-live-stream/status')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'session_expired');

        $routes = collect(app('router')->getRoutes()->getRoutes());
        foreach ([
            'api/admin/wallboard-live-stream/status',
            'api/admin/wallboard-live-stream/manifest.m3u8',
            'api/admin/wallboard-live-stream/segments/{segment}',
        ] as $uri) {
            $route = $routes->first(static fn ($candidate): bool => $candidate->uri() === $uri);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web.session:passive', $middleware);
            $this->assertNotContains('web.session', $middleware);
        }
    }

    public function test_only_live_configured_non_demo_wallboards_can_receive_the_stream(): void
    {
        $this->writeValidOutput();
        $service = app(WallboardLiveStreamDeliveryService::class);

        $live = $this->wallboard($this->playlist(
            WallboardPlaylist::DATA_MODE_LIVE,
            WallboardPlaylist::PURPOSE_NORMAL,
            $this->liveConfiguration(),
        ));
        $status = $service->statusForWallboard($live);
        $this->assertNotNull($status);
        $this->assertSame('live', $status['status']);
        $this->assertSame('/api/wallboard/live-stream/manifest.m3u8', $status['manifest_url']);
        $this->assertNotNull($status['last_packet_at']);

        $unrelated = $this->wallboard($this->playlist(
            WallboardPlaylist::DATA_MODE_LIVE,
            WallboardPlaylist::PURPOSE_NORMAL,
            $this->mapConfiguration(),
        ));
        $this->assertNull($service->statusForWallboard($unrelated));

        $demo = $this->wallboard($this->playlist(
            WallboardPlaylist::DATA_MODE_DEMO,
            WallboardPlaylist::PURPOSE_NORMAL,
            $this->liveConfiguration(),
        ));
        $this->assertNull($service->statusForWallboard($demo));

        $alarmPlaylist = $this->playlist(
            WallboardPlaylist::DATA_MODE_LIVE,
            WallboardPlaylist::PURPOSE_ALARM,
            $this->liveConfiguration(),
        );
        $alarmWallboard = $this->wallboard(
            $this->playlist(
                WallboardPlaylist::DATA_MODE_LIVE,
                WallboardPlaylist::PURPOSE_NORMAL,
                $this->mapConfiguration(),
            ),
            $alarmPlaylist,
        );
        $this->assertSame('live', $service->statusForWallboard($alarmWallboard)['status'] ?? null);
    }

    public function test_manifest_and_only_its_current_segments_are_delivered_through_internal_redirect(): void
    {
        [$manifest, $segments] = $this->writeValidOutput();
        $service = app(WallboardLiveStreamDeliveryService::class);

        $this->assertSame($manifest, $service->manifestForAdmin());
        $this->assertSame(
            ['x_accel_redirect' => '/__dis_wallboard_live/'.$segments[0]],
            $service->segmentForAdmin($segments[0]),
        );
        $this->assertNull($service->segmentForAdmin('segment-99999999999999999999.ts'));
        $this->assertNull($service->segmentForAdmin('../'.$segments[0]));

        $manager = $this->user('live-stream-delivery@example.test', ['wallboards.manage']);
        $client = $this->asAdminClient($manager);
        $manifestResponse = $client->get('/api/admin/wallboard-live-stream/manifest.m3u8')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl; charset=utf-8')
            ->assertSee($manifest, false);
        $cacheControl = (string) $manifestResponse->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $client->get('/api/admin/wallboard-live-stream/segments/'.$segments[0])
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp2t')
            ->assertHeader('X-Accel-Redirect', '/__dis_wallboard_live/'.$segments[0]);
    }

    public function test_two_immediately_prior_retained_segments_survive_a_manifest_rotation_race(): void
    {
        [, $priorSegments] = $this->writeValidOutput();
        $currentSegments = [
            'segment-00000000000000000003.ts',
            'segment-00000000000000000004.ts',
        ];
        foreach ($currentSegments as $segment) {
            file_put_contents($this->outputDirectory.DIRECTORY_SEPARATOR.$segment, 'current-segment');
        }
        file_put_contents(
            $this->outputDirectory.DIRECTORY_SEPARATOR.'index.m3u8',
            $this->manifestBody($currentSegments),
        );
        $service = app(WallboardLiveStreamDeliveryService::class);

        foreach ($priorSegments as $segment) {
            $this->assertSame(
                ['x_accel_redirect' => '/__dis_wallboard_live/'.$segment],
                $service->segmentForAdmin($segment),
            );
        }

        $tooOld = 'segment-00000000000000000000.ts';
        file_put_contents($this->outputDirectory.DIRECTORY_SEPARATOR.$tooOld, 'too-old-segment');
        $this->assertNull($service->segmentForAdmin($tooOld));

        touch(
            $this->outputDirectory.DIRECTORY_SEPARATOR.$priorSegments[0],
            now()->subSeconds(15)->getTimestamp(),
        );
        $this->assertNotNull($service->segmentForAdmin($priorSegments[0]));

        touch(
            $this->outputDirectory.DIRECTORY_SEPARATOR.$priorSegments[0],
            now()->subSeconds(30)->getTimestamp(),
        );
        $this->assertNull($service->segmentForAdmin($priorSegments[0]));
    }

    public function test_stale_or_malformed_output_fails_closed_and_is_never_delivered(): void
    {
        [, $segments] = $this->writeValidOutput();
        $service = app(WallboardLiveStreamDeliveryService::class);
        $staleAt = now()->subSeconds(30)->getTimestamp();
        touch($this->outputDirectory.DIRECTORY_SEPARATOR.'index.m3u8', $staleAt);
        foreach ($segments as $segment) {
            touch($this->outputDirectory.DIRECTORY_SEPARATOR.$segment, $staleAt);
        }

        $status = $service->statusForAdmin();
        $this->assertSame('waiting', $status['status']);
        $this->assertNotNull($status['last_packet_at']);
        $this->assertNull($service->manifestForAdmin());
        $this->assertNull($service->segmentForAdmin($segments[0]));

        file_put_contents(
            $this->outputDirectory.DIRECTORY_SEPARATOR.'index.m3u8',
            "#EXTM3U\n#EXT-X-TARGETDURATION:2\n#EXT-X-MEDIA-SEQUENCE:1\n#EXTINF:2.0,\nhttps://attacker.example.test/segment.ts\n",
        );
        $invalidStatus = $service->statusForAdmin();
        $this->assertSame('error', $invalidStatus['status']);
        $this->assertSame('rtmps://stream.example.test:1936/live', $invalidStatus['server_url']);
        $this->assertTrue($invalidStatus['stream_key_configured']);
        $this->assertNull($service->manifestForAdmin());
    }

    public function test_symlinked_manifests_and_multiply_linked_segments_fail_closed(): void
    {
        [$manifest, $segments] = $this->writeValidOutput();
        $service = app(WallboardLiveStreamDeliveryService::class);
        $manifestPath = $this->outputDirectory.DIRECTORY_SEPARATOR.'index.m3u8';
        $manifestTarget = $this->outputDirectory.DIRECTORY_SEPARATOR.'manifest-target.m3u8';
        file_put_contents($manifestTarget, $manifest);
        unlink($manifestPath);

        if (@symlink($manifestTarget, $manifestPath)) {
            $this->assertSame('error', $service->statusForAdmin()['status']);
            $this->assertNull($service->manifestForAdmin());
            unlink($manifestPath);
        } else {
            $source = file_get_contents(app_path('Services/WallboardLiveStreamDeliveryService.php'));
            $this->assertIsString($source);
            $this->assertStringContainsString('is_link($path)', $source);
        }

        file_put_contents($manifestPath, $manifest);
        $segmentPath = $this->outputDirectory.DIRECTORY_SEPARATOR.$segments[0];
        $segmentTarget = $this->outputDirectory.DIRECTORY_SEPARATOR.'segment-target.ts';
        file_put_contents($segmentTarget, 'hard-linked-segment');
        unlink($segmentPath);
        if (@link($segmentTarget, $segmentPath)) {
            $this->assertSame('error', $service->statusForAdmin()['status']);
            $this->assertNull($service->segmentForAdmin($segments[0]));
        } else {
            $source = file_get_contents(app_path('Services/WallboardLiveStreamDeliveryService.php'));
            $this->assertIsString($source);
            $this->assertStringContainsString("['nlink']", $source);
        }
    }

    public function test_paired_kiosk_routes_deny_demo_wallboards_and_do_not_disclose_ingest_details(): void
    {
        $liveWallboard = $this->wallboard($this->playlist(
            WallboardPlaylist::DATA_MODE_LIVE,
            WallboardPlaylist::PURPOSE_NORMAL,
            $this->liveConfiguration(),
        ));
        $response = $this->wallboardGet(
            '/api/wallboard/live-stream/status',
            $this->wallboardCredential($liveWallboard),
        )->assertOk()
            ->assertExactJson(['data' => [
                'status' => 'waiting',
                'manifest_url' => null,
                'last_packet_at' => null,
                'message' => 'Wachten op een geldig OBS-signaal.',
            ]]);
        $encoded = (string) $response->getContent();
        $this->assertStringNotContainsString(self::STREAM_KEY, $encoded);
        $this->assertStringNotContainsString('server_url', $encoded);
        $this->assertStringNotContainsString('stream_key_configured', $encoded);

        $demoWallboard = $this->wallboard($this->playlist(
            WallboardPlaylist::DATA_MODE_DEMO,
            WallboardPlaylist::PURPOSE_NORMAL,
            $this->liveConfiguration(),
        ));
        $this->wallboardGet(
            '/api/wallboard/live-stream/status',
            $this->wallboardCredential($demoWallboard),
        )->assertNotFound();
    }

    /** @return array{0: string, 1: list<string>} */
    private function writeValidOutput(): array
    {
        if (! is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0750, true);
        }
        $segments = [
            'segment-00000000000000000001.ts',
            'segment-00000000000000000002.ts',
        ];
        foreach ($segments as $index => $segment) {
            file_put_contents(
                $this->outputDirectory.DIRECTORY_SEPARATOR.$segment,
                'mpeg-ts-segment-'.$index,
            );
        }
        $manifest = $this->manifestBody($segments);
        file_put_contents($this->outputDirectory.DIRECTORY_SEPARATOR.'index.m3u8', $manifest);

        return [$manifest, $segments];
    }

    /** @param list<string> $segments */
    private function manifestBody(array $segments): string
    {
        $sequence = ltrim(substr($segments[0], 8, 20), '0');
        if ($sequence === '') {
            $sequence = '0';
        }

        $manifest = "#EXTM3U\n"
            ."#EXT-X-VERSION:3\n"
            ."#EXT-X-TARGETDURATION:2\n"
            ."#EXT-X-MEDIA-SEQUENCE:{$sequence}\n"
            ."#EXT-X-INDEPENDENT-SEGMENTS\n";
        foreach ($segments as $segment) {
            $manifest .= "#EXTINF:2.000000,\nsegments/{$segment}\n";
        }

        return $manifest;
    }

    /** @return array<string, mixed> */
    private function liveConfiguration(): array
    {
        return WallboardConfiguration::normalize(['pages' => [[
            'id' => 'obs-live',
            'name' => 'OBS live',
            'type' => 'live_stream',
            'duration_seconds' => 30,
            'options' => [],
        ]]]);
    }

    /** @return array<string, mixed> */
    private function mapConfiguration(): array
    {
        return WallboardConfiguration::normalize(['pages' => [[
            'id' => 'kaart',
            'name' => 'Kaart',
            'type' => 'map',
            'duration_seconds' => 30,
            'options' => [],
        ]]]);
    }

    /** @param array<string, mixed> $configuration */
    private function playlist(string $dataMode, string $purpose, array $configuration): WallboardPlaylist
    {
        return WallboardPlaylist::query()->create([
            'name' => 'Live-streamtest '.Str::ulid(),
            'data_mode' => $dataMode,
            'purpose' => $purpose,
            'configuration' => $configuration,
            'version' => 1,
        ]);
    }

    private function wallboard(
        WallboardPlaylist $playlist,
        ?WallboardPlaylist $activeDeploymentPlaylist = null,
    ): Wallboard {
        return Wallboard::query()->create([
            'name' => 'Live-streamwallboard '.Str::ulid(),
            'playlist_id' => $playlist->id,
            'active_deployment_playlist_id' => $activeDeploymentPlaylist?->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => (array) $playlist->configuration,
            'rotation_started_at' => now(),
            'is_enabled' => true,
        ]);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'Live Stream Test',
            'first_name' => 'Live',
            'last_name' => 'Stream Test',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'live-stream-test-'.Str::lower((string) Str::ulid()),
            'display_name' => 'Live-streamtestrol',
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
        $token = $user->createToken(
            'Wallboard live-stream admin test',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function asWebSession(User $user, int $lastActivityAt): static
    {
        config([
            'app.url' => 'https://dis.example.test',
            'session.trusted_origins' => ['https://dis.example.test'],
            'sanctum.stateful' => ['dis.example.test'],
        ]);
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        return $this->actingAs($user, 'web')
            ->withSession([
                WebSessionService::KEY_AUTHENTICATED_AT => $lastActivityAt,
                WebSessionService::KEY_LAST_ACTIVITY_AT => $lastActivityAt,
                WebSessionService::KEY_AUTH_VERSION => (int) $user->auth_session_version,
            ])
            ->withHeaders([
                'Accept' => 'application/json',
                'Origin' => 'https://dis.example.test',
                'Referer' => 'https://dis.example.test/',
                'Sec-Fetch-Site' => 'same-origin',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->withServerVariables([
                'HTTP_HOST' => 'dis.example.test',
                'SERVER_NAME' => 'dis.example.test',
                'SERVER_PORT' => 443,
                'HTTPS' => 'on',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REMOTE_ADDR' => '192.0.2.72',
            ]);
    }

    private function wallboardCredential(Wallboard $wallboard): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => app(WallboardSessionService::class)->tokenHash($secret),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => null,
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
            ->withHeaders([
                'Origin' => 'https://dis.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->get($uri);
    }
}
