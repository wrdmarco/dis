<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWebCsrfToken;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\WallboardLiveStreamKeyRequestService;
use App\Services\WallboardLiveStreamKeyService;
use App\Services\WebSessionService;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class WallboardLiveStreamKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_KEY = 'Current_Managed_Stream_Key_1234567890-abcdefghijklmnopqrstuvwxyz';

    private string $root;

    private string $envPath;

    private string $requestRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyWebCsrfToken::class);

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dis-wallboard-key-'.bin2hex(random_bytes(8));
        $this->requestRoot = $this->root.DIRECTORY_SEPARATOR.'requests';
        $this->envPath = $this->root.DIRECTORY_SEPARATOR.'.env';
        $this->assertTrue(mkdir($this->requestRoot, 0770, true));
        $this->writeManagedKey(self::CURRENT_KEY);
        config()->set('wallboard_live_stream.managed_env_path', $this->envPath);
        config()->set('wallboard_live_stream.key_request_directory', $this->requestRoot);
        config()->set('wallboard_live_stream.stream_key', str_repeat('Stale_Config_Value_', 3));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() && ! $entry->isLink()
                    ? rmdir($entry->getPathname())
                    : unlink($entry->getPathname());
            }
            rmdir($this->root);
        }

        parent::tearDown();
    }

    public function test_reveal_requires_a_stateful_two_factor_web_session_and_never_leaks_through_status_or_audit(): void
    {
        $this->postJson('/api/admin/wallboard-live-stream/stream-key/reveal')->assertUnauthorized();

        $denied = $this->user('stream-key-denied@example.test');
        $this->asWebSession($denied)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/reveal')
            ->assertForbidden();

        $incompleteTwoFactor = $this->user(
            'stream-key-incomplete-two-factor@example.test',
            ['wallboards.manage'],
        );
        $incompleteTwoFactor->forceFill([
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->asWebSession($incompleteTwoFactor)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/reveal')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_setup_required');

        $manager = $this->user('stream-key-reveal@example.test', ['wallboards.manage']);
        $token = $manager->createToken(
            'Stream key web bearer is not a browser session',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        $this->resetRequestState();
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/reveal')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'stateful_web_session_required');

        $response = $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/reveal')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonPath('data.stream_key', self::CURRENT_KEY)
            ->assertJsonPath('data.stream_key_version', $this->keyVersion(self::CURRENT_KEY));

        $this->assertStringNotContainsString('Stale_Config_Value_', (string) $response->getContent());
        $status = $this->asWebSession($manager)
            ->getJson('/api/admin/wallboard-live-stream/status')
            ->assertOk();
        $this->assertStringNotContainsString(self::CURRENT_KEY, (string) $status->getContent());
        $this->assertStringNotContainsString('key_sha256', (string) $status->getContent());

        $audit = AuditLog::query()->where('action', 'wallboard.live_stream.key_revealed')->sole();
        $this->assertSame($manager->id, $audit->actor_id);
        $this->assertStringNotContainsString(
            self::CURRENT_KEY,
            AuditLog::query()->get()->toJson(),
        );
    }

    public function test_rotation_publishes_the_server_owned_cas_request_and_returns_only_the_verified_active_key(): void
    {
        $requestId = str_repeat('a', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $result = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $payload = null;
        $broker = new WallboardLiveStreamKeyRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function (int $microseconds) use ($pending, $result, &$payload): void {
                $this->assertSame(250_000, $microseconds);
                $contents = file_get_contents($pending);
                $this->assertIsString($contents);
                $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                if (PHP_OS_FAMILY !== 'Windows') {
                    $this->assertSame(0600, fileperms($pending) & 0777);
                }
                $this->writeManagedKey((string) $payload['stream_key']);
                $this->assertTrue(unlink($pending));
                $this->assertNotFalse(file_put_contents($result, json_encode([
                    'state' => 'succeeded',
                    'exit_code' => 0,
                    'output' => 'Stream key rotation completed.',
                    'finished_at' => '2026-08-07T12:00:00Z',
                ], JSON_THROW_ON_ERROR)));
                $this->assertTrue(chmod($result, 0600));
            },
        );
        $this->app->instance(WallboardLiveStreamKeyRequestService::class, $broker);
        $this->app->forgetInstance(WallboardLiveStreamKeyService::class);
        $manager = $this->user('stream-key-rotate@example.test', ['wallboards.manage']);

        $response = $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/rotate', [
                'confirmation' => 'WISSELEN',
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.previous_key_revoked', true)
            ->assertJsonPath('data.obs_reconnect_required', true);

        $newKey = (string) $response->json('data.stream_key');
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{64}\z/D', $newKey);
        $this->assertNotSame(self::CURRENT_KEY, $newKey);
        $this->assertSame($this->keyVersion($newKey), $response->json('data.stream_key_version'));
        $this->assertSame($newKey, $payload['stream_key'] ?? null);
        $this->assertSame('rotate', $payload['operation'] ?? null);
        $this->assertSame(hash('sha256', self::CURRENT_KEY), $payload['expected_key_sha256'] ?? null);
        $this->assertSame($manager->id, $payload['actor_id'] ?? null);
        $this->assertSame(120, strtotime((string) $payload['expires_at']) - strtotime((string) $payload['created_at']));
        $this->assertSame([
            'operation',
            'stream_key',
            'expected_key_sha256',
            'actor_id',
            'created_at',
            'expires_at',
        ], array_keys($payload));
        $this->assertFileDoesNotExist($pending);
        $this->assertFileDoesNotExist($result);
        $this->assertStringNotContainsString(self::CURRENT_KEY, AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString($newKey, AuditLog::query()->get()->toJson());
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboard.live_stream.key_rotation_requested')->exists());
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboard.live_stream.key_rotated')->exists());
    }

    public function test_key_mutations_require_a_session_bound_csrf_token(): void
    {
        $this->withMiddleware(VerifyWebCsrfToken::class);
        $manager = $this->user('stream-key-csrf@example.test', ['wallboards.manage']);

        $this->asWebSession($manager, includeCsrf: false)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/reveal')
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'csrf_token_mismatch');
        $this->asWebSession($manager, includeCsrf: false)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/rotate', [
                'confirmation' => 'WISSELEN',
            ])
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'csrf_token_mismatch');
        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/reveal')
            ->assertOk()
            ->assertJsonPath('data.stream_key', self::CURRENT_KEY);
    }

    public function test_key_endpoints_apply_their_strict_dedicated_rate_limits(): void
    {
        $revealManager = $this->user('stream-key-reveal-limit@example.test', ['wallboards.manage']);
        $client = $this->asWebSession($revealManager);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $client->postJson('/api/admin/wallboard-live-stream/stream-key/reveal')->assertOk();
        }
        $client->postJson('/api/admin/wallboard-live-stream/stream-key/reveal')
            ->assertTooManyRequests();

        $rotateManager = $this->user('stream-key-rotate-limit@example.test', ['wallboards.manage']);
        $client = $this->asWebSession($rotateManager);
        $client->postJson('/api/admin/wallboard-live-stream/stream-key/rotate', [
            'confirmation' => 'invalid',
        ])->assertUnprocessable();
        $client->postJson('/api/admin/wallboard-live-stream/stream-key/rotate', [
            'confirmation' => 'invalid',
        ])->assertTooManyRequests();
    }

    public function test_result_reader_locks_the_published_inode_before_inspecting_it(): void
    {
        $source = file_get_contents(app_path('Services/WallboardLiveStreamKeyRequestService.php'));
        $this->assertIsString($source);
        $sharedLock = strpos($source, 'flock($handle, LOCK_SH)');
        $pathRestat = strpos($source, '$pathMetadata = $this->pathMetadata($path);', $sharedLock ?: 0);
        $metadataRead = strpos($source, '$metadata = fstat($handle);', $sharedLock ?: 0);

        $this->assertNotFalse($sharedLock);
        $this->assertNotFalse($pathRestat);
        $this->assertNotFalse($metadataRead);
        $this->assertLessThan($pathRestat, $sharedLock);
        $this->assertLessThan($metadataRead, $pathRestat);
        $this->assertLessThan($metadataRead, $sharedLock);
        $this->assertStringContainsString('$pathMetadata === false', $source);
        $this->assertStringContainsString('flock($handle, LOCK_UN);', $source);
    }

    public function test_result_reader_fails_closed_when_the_path_disappears_after_locking_the_open_inode(): void
    {
        $requestId = str_repeat('d', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $resultPath = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $metadataReads = 0;
        $broker = new WallboardLiveStreamKeyRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function () use ($pending, $resultPath): void {
                $this->assertTrue(unlink($pending));
                $this->assertNotFalse(file_put_contents($resultPath, json_encode([
                    'state' => 'succeeded',
                    'exit_code' => 0,
                    'output' => 'A late success result that must not be accepted.',
                    'finished_at' => '2026-08-07T12:00:00Z',
                ], JSON_THROW_ON_ERROR)));
                $this->assertTrue(chmod($resultPath, 0600));
            },
            pathMetadataReader: function (string $path) use (&$metadataReads): array|false {
                $metadataReads++;

                return $metadataReads === 1 ? lstat($path) : false;
            },
        );

        $result = $broker->rotate(
            self::CURRENT_KEY,
            hash('sha256', str_repeat('p', 64)),
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            1,
        );

        $this->assertSame('invalid_result', $result['outcome']);
        $this->assertNull($result['exit_code']);
        $this->assertSame(2, $metadataReads);
        $this->assertFileDoesNotExist($resultPath);
    }

    public function test_rotation_confirmation_is_exact(): void
    {
        $manager = $this->user('stream-key-confirmation@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/rotate', [
                'confirmation' => 'wisselen',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['confirmation']]]);
        $this->assertSame([], glob($this->requestRoot.DIRECTORY_SEPARATOR.'*.pending') ?: []);
    }

    public function test_worker_success_without_the_managed_env_change_fails_closed(): void
    {
        $requestId = str_repeat('b', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $result = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $generatedKey = null;
        $this->app->instance(WallboardLiveStreamKeyRequestService::class, new WallboardLiveStreamKeyRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function () use ($pending, $result, &$generatedKey): void {
                $payload = json_decode((string) file_get_contents($pending), true, flags: JSON_THROW_ON_ERROR);
                $generatedKey = (string) $payload['stream_key'];
                unlink($pending);
                file_put_contents($result, json_encode([
                    'state' => 'succeeded',
                    'exit_code' => 0,
                    'output' => 'Reported success without persistence.',
                    'finished_at' => '2026-08-07T12:00:00Z',
                ], JSON_THROW_ON_ERROR));
                chmod($result, 0600);
            },
        ));
        $this->app->forgetInstance(WallboardLiveStreamKeyService::class);
        $manager = $this->user('stream-key-persistence@example.test', ['wallboards.manage']);

        $response = $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/rotate', [
                'confirmation' => 'WISSELEN',
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'wallboard_live_stream_key_rotation_failed');
        $this->assertIsString($generatedKey);
        $this->assertStringNotContainsString($generatedKey, (string) $response->getContent());
        $this->assertSame(self::CURRENT_KEY, $this->managedKey());
    }

    public function test_worker_cas_conflict_returns_conflict_without_disclosing_either_candidate_key(): void
    {
        $requestId = str_repeat('c', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $result = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $otherKey = 'Other_Admin_Stream_Key_1234567890-abcdefghijklmnopqrstuvwxyz-ABCDE';
        $generatedKey = null;
        $this->app->instance(WallboardLiveStreamKeyRequestService::class, new WallboardLiveStreamKeyRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function () use ($pending, $result, $otherKey, &$generatedKey): void {
                $payload = json_decode((string) file_get_contents($pending), true, flags: JSON_THROW_ON_ERROR);
                $generatedKey = (string) $payload['stream_key'];
                $this->writeManagedKey($otherKey);
                unlink($pending);
                file_put_contents($result, json_encode([
                    'state' => 'failed',
                    'exit_code' => 3,
                    'output' => 'The expected key no longer matches.',
                    'finished_at' => '2026-08-07T12:00:00Z',
                ], JSON_THROW_ON_ERROR));
                chmod($result, 0600);
            },
        ));
        $this->app->forgetInstance(WallboardLiveStreamKeyService::class);
        $manager = $this->user('stream-key-conflict@example.test', ['wallboards.manage']);

        $response = $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/stream-key/rotate', [
                'confirmation' => 'WISSELEN',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'wallboard_live_stream_key_changed');
        $this->assertStringNotContainsString((string) $generatedKey, (string) $response->getContent());
        $this->assertStringNotContainsString($otherKey, (string) $response->getContent());
        $this->assertSame($otherKey, $this->managedKey());
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'Stream Key Test',
            'first_name' => 'Stream',
            'last_name' => 'Key Test',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'stream-key-test-'.Str::lower((string) Str::ulid()),
            'display_name' => 'Stream-keytestrol',
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

    private function asWebSession(User $user, bool $includeCsrf = true): static
    {
        config([
            'app.url' => 'https://dis.example.test',
            'session.trusted_origins' => ['https://dis.example.test'],
            'sanctum.stateful' => ['dis.example.test'],
        ]);
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->resetRequestState();
        $timestamp = now()->getTimestamp();
        $csrfToken = hash('sha256', 'wallboard-live-stream-key-session-'.$user->id);
        $headers = [
            'Accept' => 'application/json',
            'Origin' => 'https://dis.example.test',
            'Referer' => 'https://dis.example.test/',
            'Sec-Fetch-Site' => 'same-origin',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
        if ($includeCsrf) {
            $headers['X-CSRF-TOKEN'] = $csrfToken;
        }

        return $this->actingAs($user, 'web')
            ->withSession([
                '_token' => $csrfToken,
                WebSessionService::KEY_AUTHENTICATED_AT => $timestamp,
                WebSessionService::KEY_LAST_ACTIVITY_AT => $timestamp,
                WebSessionService::KEY_AUTH_VERSION => (int) $user->auth_session_version,
            ])
            ->withHeaders($headers)
            ->withServerVariables([
                'HTTP_HOST' => 'dis.example.test',
                'SERVER_NAME' => 'dis.example.test',
                'SERVER_PORT' => 443,
                'HTTPS' => 'on',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REMOTE_ADDR' => '192.0.2.80',
            ]);
    }

    private function resetRequestState(): void
    {
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];
    }

    private function writeManagedKey(string $streamKey): void
    {
        $this->assertNotFalse(file_put_contents(
            $this->envPath,
            "APP_ENV=testing\nWALLBOARD_LIVE_STREAM_STREAM_KEY={$streamKey}\n",
        ));
    }

    private function managedKey(): string
    {
        $contents = (string) file_get_contents($this->envPath);
        preg_match('/^WALLBOARD_LIVE_STREAM_STREAM_KEY=(.+)$/m', $contents, $matches);

        return (string) ($matches[1] ?? '');
    }

    private function keyVersion(string $streamKey): string
    {
        return hash_hmac('sha256', $streamKey, (string) config('app.key'));
    }
}
