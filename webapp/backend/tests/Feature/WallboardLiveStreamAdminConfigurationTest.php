<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWebCsrfToken;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\WallboardLiveStreamConfigurationService;
use App\Services\WallboardLiveStreamKeyRequestService;
use App\Services\WallboardLiveStreamProcessService;
use App\Services\WebSessionService;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class WallboardLiveStreamAdminConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const CREATED_KEY = 'Portal_Created_Stream_Key_1234567890-abcdefghijklmnopqrstuvwxyz';

    private const INITIAL_CONFIG_SHA256 = '9d2d9898ad868b14d1c200b846ebd1c080f1c5ce85197699e71781a087dfe2eb';

    private string $root;

    private string $envPath;

    private string $requestRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyWebCsrfToken::class);

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dis-wallboard-configuration-'.bin2hex(random_bytes(8));
        $this->requestRoot = $this->root.DIRECTORY_SEPARATOR.'requests';
        $this->envPath = $this->root.DIRECTORY_SEPARATOR.'.env';
        $this->assertTrue(mkdir($this->requestRoot, 0770, true));
        $this->writeManagedEnvironment($this->initialConfiguration());
        config()->set('wallboard_live_stream.managed_env_path', $this->envPath);
        config()->set('wallboard_live_stream.key_request_directory', $this->requestRoot);
        config()->set('wallboard_live_stream.enabled', false);
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

    public function test_configuration_requires_permission_two_factor_and_a_stateful_web_session(): void
    {
        $payload = $this->enabledPayload();
        $this->postJson('/api/admin/wallboard-live-stream/configuration', $payload)->assertUnauthorized();

        $denied = $this->user('stream-configuration-denied@example.test');
        $this->asWebSession($denied)
            ->postJson('/api/admin/wallboard-live-stream/configuration', $payload)
            ->assertForbidden();

        $incompleteTwoFactor = $this->user(
            'stream-configuration-two-factor@example.test',
            ['wallboards.manage'],
        );
        $incompleteTwoFactor->forceFill([
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->asWebSession($incompleteTwoFactor)
            ->postJson('/api/admin/wallboard-live-stream/configuration', $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_setup_required');

        $manager = $this->user('stream-configuration-bearer@example.test', ['wallboards.manage']);
        $token = $manager->createToken(
            'Configuration bearer is not a browser session',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        $this->resetRequestState();
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/wallboard-live-stream/configuration', $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'stateful_web_session_required');
    }

    public function test_configuration_digest_contract_uses_compact_ordered_json_without_a_newline(): void
    {
        $this->assertSame(self::INITIAL_CONFIG_SHA256, $this->digest($this->initialConfiguration()));
        $process = app(WallboardLiveStreamProcessService::class);
        $this->assertFalse($process->isValidPublicHost('999.999.999.999'));
        $this->assertFalse($process->isValidPublicHost('001.002.003.004'));
    }

    public function test_legacy_boolean_casing_and_zero_padded_port_use_the_worker_canonical_revision(): void
    {
        $configuration = [
            'enabled' => true,
            'public_host' => 'legacy-stream.example.test',
            'bind_address' => '0.0.0.0',
            'rtmps_port' => 1936,
            'tls_certificate_path' => '/opt/dis-data/certs/fullchain.pem',
            'tls_private_key_path' => '/opt/dis-data/certs/privkey.pem',
            'stream_key_configured' => true,
        ];
        $this->writeManagedEnvironment($configuration, self::CREATED_KEY);
        $contents = file_get_contents($this->envPath);
        $this->assertIsString($contents);
        $contents = str_replace(
            ['WALLBOARD_LIVE_STREAM_ENABLED=true', 'WALLBOARD_LIVE_STREAM_RTMPS_PORT=1936'],
            ['WALLBOARD_LIVE_STREAM_ENABLED=TRUE', 'WALLBOARD_LIVE_STREAM_RTMPS_PORT=01936'],
            $contents,
        );
        $this->assertNotFalse(file_put_contents($this->envPath, $contents));
        $manager = $this->user('stream-configuration-legacy-canonical@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->getJson('/api/admin/wallboard-live-stream/status')
            ->assertOk()
            ->assertJsonPath('data.configuration.enabled', true)
            ->assertJsonPath('data.configuration.rtmps_port', 1936)
            ->assertJsonPath('data.configuration_revision', $this->digest($configuration));
    }

    public function test_disabled_configuration_may_be_incomplete_but_bind_address_and_port_remain_valid(): void
    {
        config()->set('wallboard_live_stream.key_request_directory', $this->root.DIRECTORY_SEPARATOR.'missing');
        $manager = $this->user('stream-configuration-disabled@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                'enabled' => false,
                'public_host' => '',
                'rtmps_bind_address' => '127.0.0.1',
                'rtmps_port' => 1936,
                'tls_certificate_path' => '',
                'tls_private_key_path' => '',
                'configuration_revision' => self::INITIAL_CONFIG_SHA256,
            ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'wallboard_live_stream_configuration_update_failed');

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                'enabled' => false,
                'public_host' => '',
                'rtmps_bind_address' => '',
                'rtmps_port' => 1936,
                'tls_certificate_path' => '',
                'tls_private_key_path' => '',
                'configuration_revision' => self::INITIAL_CONFIG_SHA256,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['rtmps_bind_address']]]);
    }

    public function test_enabled_configuration_rejects_incomplete_or_unsafe_root_paths(): void
    {
        $manager = $this->user('stream-configuration-validation@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                ...$this->enabledPayload(),
                'public_host' => '',
                'tls_certificate_path' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => [
                'public_host',
                'tls_certificate_path',
            ]]]);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                ...$this->enabledPayload(),
                'tls_certificate_path' => '/etc/letsencrypt/live/../../shadow',
                'tls_private_key_path' => '/etc/passwd',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => [
                'tls_certificate_path',
                'tls_private_key_path',
            ]]]);
    }

    public function test_legacy_managed_tls_paths_remain_visible_and_can_be_migrated_to_the_portal_allowlist(): void
    {
        $legacyConfiguration = [
            'enabled' => true,
            'public_host' => 'legacy-stream.example.test',
            'bind_address' => '0.0.0.0',
            'rtmps_port' => 1936,
            'tls_certificate_path' => '/opt/dis-data/certs/fullchain.pem',
            'tls_private_key_path' => '/opt/dis-data/certs/privkey.pem',
            'stream_key_configured' => true,
        ];
        $this->writeManagedEnvironment($legacyConfiguration, self::CREATED_KEY);
        $manager = $this->user('stream-configuration-legacy-migration@example.test', ['wallboards.manage']);

        $status = $this->asWebSession($manager)
            ->getJson('/api/admin/wallboard-live-stream/status')
            ->assertOk()
            ->assertJsonPath(
                'data.configuration.tls_certificate_path',
                '/opt/dis-data/certs/fullchain.pem',
            )
            ->assertJsonPath(
                'data.configuration.tls_private_key_path',
                '/opt/dis-data/certs/privkey.pem',
            )
            ->assertJsonPath('data.stream_key_configured', true);
        $legacyRevision = $this->digest($legacyConfiguration);
        $this->assertSame($legacyRevision, $status->json('data.configuration_revision'));

        $requestId = str_repeat('b', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $result = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $this->app->instance(WallboardLiveStreamKeyRequestService::class, new WallboardLiveStreamKeyRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function () use ($pending, $result, $legacyRevision): void {
                $payload = json_decode((string) file_get_contents($pending), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($legacyRevision, $payload['expected_config_sha256']);
                $this->assertSame(
                    '/etc/letsencrypt/live/stream.example.test/fullchain.pem',
                    $payload['tls_certificate_path'],
                );
                $postConfiguration = [
                    'enabled' => $payload['enabled'],
                    'public_host' => $payload['public_host'],
                    'bind_address' => $payload['bind_address'],
                    'rtmps_port' => $payload['rtmps_port'],
                    'tls_certificate_path' => $payload['tls_certificate_path'],
                    'tls_private_key_path' => $payload['tls_private_key_path'],
                    'stream_key_configured' => true,
                ];
                $this->writeManagedEnvironment($postConfiguration, self::CREATED_KEY);
                $this->assertTrue(unlink($pending));
                $this->assertNotFalse(file_put_contents($result, json_encode([
                    'state' => 'succeeded',
                    'exit_code' => 0,
                    'output' => 'Legacy TLS paths migrated.',
                    'finished_at' => '2026-08-07T12:00:00Z',
                    'key_created' => false,
                    'config_sha256' => $this->digest($postConfiguration),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
                $this->assertTrue(chmod($result, 0600));
            },
        ));
        $this->app->forgetInstance(WallboardLiveStreamConfigurationService::class);

        $response = $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                ...$this->enabledPayload(),
                'configuration_revision' => $legacyRevision,
            ])
            ->assertOk()
            ->assertJsonPath('data.key_created', false)
            ->assertJsonPath('data.configuration_changed', true)
            ->assertJsonPath(
                'data.status.configuration.tls_certificate_path',
                '/etc/letsencrypt/live/stream.example.test/fullchain.pem',
            )
            ->assertJsonPath(
                'data.status.configuration.tls_private_key_path',
                '/etc/letsencrypt/live/stream.example.test/privkey.pem',
            );

        $this->assertStringNotContainsString(self::CREATED_KEY, (string) $response->getContent());
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboard.live_stream.configuration_updated')
            ->exists());
    }

    public function test_unchanged_disabled_configuration_does_not_reload_the_stream_services(): void
    {
        $manager = $this->user('stream-configuration-no-op@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                'enabled' => false,
                'public_host' => '',
                'rtmps_bind_address' => '0.0.0.0',
                'rtmps_port' => 1936,
                'tls_certificate_path' => '',
                'tls_private_key_path' => '',
                'configuration_revision' => self::INITIAL_CONFIG_SHA256,
            ])
            ->assertOk()
            ->assertJsonPath('data.key_created', false)
            ->assertJsonPath('data.configuration_changed', false)
            ->assertJsonPath('data.status.configuration_revision', self::INITIAL_CONFIG_SHA256);

        $this->assertSame([], glob($this->requestRoot.DIRECTORY_SEPARATOR.'*.pending') ?: []);
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboard.live_stream.configuration_update_skipped')
            ->exists());
        $this->assertFalse(AuditLog::query()
            ->where('action', 'wallboard.live_stream.configuration_update_requested')
            ->exists());
    }

    public function test_first_key_creation_response_uses_the_committed_state_while_php_still_has_keyless_configuration(): void
    {
        $keylessConfiguration = [
            'enabled' => true,
            'public_host' => 'stream.example.test',
            'bind_address' => '0.0.0.0',
            'rtmps_port' => 1936,
            'tls_certificate_path' => '/etc/letsencrypt/live/stream.example.test/fullchain.pem',
            'tls_private_key_path' => '/etc/letsencrypt/live/stream.example.test/privkey.pem',
            'stream_key_configured' => false,
        ];
        $this->writeManagedEnvironment($keylessConfiguration);
        config()->set('wallboard_live_stream.enabled', true);
        config()->set('wallboard_live_stream.stream_key', '');

        $requestId = str_repeat('c', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $result = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $this->app->instance(WallboardLiveStreamKeyRequestService::class, new WallboardLiveStreamKeyRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function () use ($pending, $result, $keylessConfiguration): void {
                $postConfiguration = [
                    ...$keylessConfiguration,
                    'stream_key_configured' => true,
                ];
                $this->writeManagedEnvironment($postConfiguration, self::CREATED_KEY);
                $this->assertTrue(unlink($pending));
                $this->assertNotFalse(file_put_contents($result, json_encode([
                    'state' => 'succeeded',
                    'exit_code' => 0,
                    'output' => 'First Stream Key created.',
                    'finished_at' => '2026-08-07T12:00:00Z',
                    'key_created' => true,
                    'config_sha256' => $this->digest($postConfiguration),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
                $this->assertTrue(chmod($result, 0600));
            },
        ));
        $this->app->forgetInstance(WallboardLiveStreamConfigurationService::class);
        $manager = $this->user('stream-configuration-first-key@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                'enabled' => true,
                'public_host' => $keylessConfiguration['public_host'],
                'rtmps_bind_address' => $keylessConfiguration['bind_address'],
                'rtmps_port' => $keylessConfiguration['rtmps_port'],
                'tls_certificate_path' => $keylessConfiguration['tls_certificate_path'],
                'tls_private_key_path' => $keylessConfiguration['tls_private_key_path'],
                'configuration_revision' => $this->digest($keylessConfiguration),
            ])
            ->assertOk()
            ->assertJsonPath('data.key_created', true)
            ->assertJsonPath('data.configuration_changed', false)
            ->assertJsonPath('data.status.stream_key_configured', true)
            ->assertJsonPath('data.status.status', 'waiting');
    }

    public function test_browser_cannot_supply_worker_owned_configuration_fields(): void
    {
        $manager = $this->user('stream-configuration-worker-fields@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                ...$this->enabledPayload(),
                'operation' => 'configure',
                'stream_key' => self::CREATED_KEY,
                'expected_config_sha256' => self::INITIAL_CONFIG_SHA256,
                'key_created' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => [
                'operation',
                'stream_key',
                'expected_config_sha256',
                'key_created',
            ]]]);

        $this->assertSame([], glob($this->requestRoot.DIRECTORY_SEPARATOR.'*.pending') ?: []);
    }

    public function test_disabled_incomplete_configuration_is_saved_without_generating_a_key(): void
    {
        $requestId = str_repeat('d', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $result = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $this->app->instance(WallboardLiveStreamKeyRequestService::class, new WallboardLiveStreamKeyRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function () use ($pending, $result): void {
                $payload = json_decode((string) file_get_contents($pending), true, flags: JSON_THROW_ON_ERROR);
                $postConfiguration = [
                    'enabled' => false,
                    'public_host' => $payload['public_host'],
                    'bind_address' => $payload['bind_address'],
                    'rtmps_port' => $payload['rtmps_port'],
                    'tls_certificate_path' => $payload['tls_certificate_path'],
                    'tls_private_key_path' => $payload['tls_private_key_path'],
                    'stream_key_configured' => false,
                ];
                $this->writeManagedEnvironment($postConfiguration);
                unlink($pending);
                file_put_contents($result, json_encode([
                    'state' => 'succeeded',
                    'exit_code' => 0,
                    'output' => 'Disabled configuration stored.',
                    'finished_at' => '2026-08-07T12:00:00Z',
                    'key_created' => false,
                    'config_sha256' => $this->digest($postConfiguration),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                chmod($result, 0600);
            },
        ));
        $this->app->forgetInstance(WallboardLiveStreamConfigurationService::class);
        $manager = $this->user('stream-configuration-disabled-success@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                'enabled' => false,
                'public_host' => '',
                'rtmps_bind_address' => '127.0.0.1',
                'rtmps_port' => 1940,
                'tls_certificate_path' => '',
                'tls_private_key_path' => '',
                'configuration_revision' => self::INITIAL_CONFIG_SHA256,
            ])
            ->assertOk()
            ->assertJsonPath('data.key_created', false)
            ->assertJsonPath('data.configuration_changed', true)
            ->assertJsonPath('data.status.configuration.enabled', false)
            ->assertJsonPath('data.status.configuration.rtmps_bind_address', '127.0.0.1')
            ->assertJsonPath('data.status.stream_key_configured', false);
        $this->assertMatchesRegularExpression(
            '/^WALLBOARD_LIVE_STREAM_STREAM_KEY=$/m',
            (string) file_get_contents($this->envPath),
        );
    }

    public function test_successful_configuration_is_cas_guarded_initializes_the_key_and_returns_only_safe_status(): void
    {
        $requestId = str_repeat('e', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $result = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $payload = null;
        $broker = new WallboardLiveStreamKeyRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function (int $microseconds) use ($pending, $result, &$payload): void {
                $this->assertSame(250_000, $microseconds);
                $payload = json_decode((string) file_get_contents($pending), true, flags: JSON_THROW_ON_ERROR);
                $postConfiguration = [
                    'enabled' => $payload['enabled'],
                    'public_host' => $payload['public_host'],
                    'bind_address' => $payload['bind_address'],
                    'rtmps_port' => $payload['rtmps_port'],
                    'tls_certificate_path' => $payload['tls_certificate_path'],
                    'tls_private_key_path' => $payload['tls_private_key_path'],
                    'stream_key_configured' => true,
                ];
                $this->writeManagedEnvironment($postConfiguration, self::CREATED_KEY);
                $this->assertTrue(unlink($pending));
                $this->assertNotFalse(file_put_contents($result, json_encode([
                    'state' => 'succeeded',
                    'exit_code' => 0,
                    'output' => 'Live-stream configuration completed.',
                    'finished_at' => '2026-08-07T12:00:00Z',
                    'key_created' => true,
                    'config_sha256' => $this->digest($postConfiguration),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
                $this->assertTrue(chmod($result, 0600));
            },
        );
        $this->app->instance(WallboardLiveStreamKeyRequestService::class, $broker);
        $this->app->forgetInstance(WallboardLiveStreamConfigurationService::class);
        $manager = $this->user('stream-configuration-success@example.test', ['wallboards.manage']);

        $response = $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', $this->enabledPayload())
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonPath('data.key_created', true)
            ->assertJsonPath('data.configuration_changed', true)
            ->assertJsonPath('data.status.status', 'waiting')
            ->assertJsonPath('data.status.configuration.enabled', true)
            ->assertJsonPath('data.status.configuration.public_host', 'stream.example.test')
            ->assertJsonPath('data.status.configuration.rtmps_bind_address', '0.0.0.0')
            ->assertJsonPath('data.status.configuration.rtmps_port', 1936)
            ->assertJsonPath(
                'data.status.configuration.tls_certificate_path',
                '/etc/letsencrypt/live/stream.example.test/fullchain.pem',
            )
            ->assertJsonPath('data.status.stream_key_configured', true)
            ->assertJsonPath('data.status.configuration_revision', '654269efb59cccce8cb398392404b389fa8a36fc2c4a8c0f0bd9cd7c24d9ade8');

        $this->assertIsArray($payload);
        $this->assertSame([
            'operation',
            'enabled',
            'public_host',
            'bind_address',
            'rtmps_port',
            'tls_certificate_path',
            'tls_private_key_path',
            'expected_config_sha256',
            'actor_id',
            'created_at',
            'expires_at',
        ], array_keys($payload));
        $this->assertSame('configure', $payload['operation']);
        $this->assertSame(self::INITIAL_CONFIG_SHA256, $payload['expected_config_sha256']);
        $this->assertSame($manager->id, $payload['actor_id']);
        $this->assertArrayNotHasKey('stream_key', $payload);
        $this->assertStringNotContainsString(self::CREATED_KEY, (string) $response->getContent());
        $this->assertStringNotContainsString(self::CREATED_KEY, AuditLog::query()->get()->toJson());
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboard.live_stream.configuration_update_requested')->exists());
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboard.live_stream.configuration_updated')->exists());
    }

    public function test_configuration_conflict_is_reported_without_accepting_a_stale_result(): void
    {
        $requestId = str_repeat('f', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $result = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $this->app->instance(WallboardLiveStreamKeyRequestService::class, new WallboardLiveStreamKeyRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function () use ($pending, $result): void {
                unlink($pending);
                file_put_contents($result, json_encode([
                    'state' => 'failed',
                    'exit_code' => 3,
                    'output' => 'The expected configuration no longer matches.',
                    'finished_at' => '2026-08-07T12:00:00Z',
                ], JSON_THROW_ON_ERROR));
                chmod($result, 0600);
            },
        ));
        $this->app->forgetInstance(WallboardLiveStreamConfigurationService::class);
        $manager = $this->user('stream-configuration-conflict@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', $this->enabledPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'wallboard_live_stream_configuration_changed')
            ->assertJsonPath('error.details.request_id', $requestId);
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboard.live_stream.configuration_update_failed')
            ->where('metadata->reason', 'configuration_changed')
            ->exists());
    }

    public function test_stale_public_configuration_revision_is_rejected_before_publication(): void
    {
        $manager = $this->user('stream-configuration-stale-revision@example.test', ['wallboards.manage']);

        $this->asWebSession($manager)
            ->postJson('/api/admin/wallboard-live-stream/configuration', [
                ...$this->enabledPayload(),
                'configuration_revision' => str_repeat('0', 64),
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'wallboard_live_stream_configuration_changed')
            ->assertJsonPath('error.details', []);

        $this->assertSame([], glob($this->requestRoot.DIRECTORY_SEPARATOR.'*.pending') ?: []);
        $this->assertFalse(AuditLog::query()
            ->where('action', 'wallboard.live_stream.configuration_update_requested')
            ->exists());
    }

    public function test_configuration_update_requires_csrf_and_has_a_dedicated_rate_limit(): void
    {
        config()->set('wallboard_live_stream.key_request_directory', $this->root.DIRECTORY_SEPARATOR.'missing');
        $this->withMiddleware(VerifyWebCsrfToken::class);
        $manager = $this->user('stream-configuration-csrf@example.test', ['wallboards.manage']);
        $this->asWebSession($manager, includeCsrf: false)
            ->postJson('/api/admin/wallboard-live-stream/configuration', $this->enabledPayload())
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'csrf_token_mismatch');

        $rateLimited = $this->user('stream-configuration-rate-limit@example.test', ['wallboards.manage']);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->asWebSession($rateLimited, remoteAddress: '192.0.2.82')
                ->postJson('/api/admin/wallboard-live-stream/configuration', $this->enabledPayload())
                ->assertServiceUnavailable();
        }
        $this->asWebSession($rateLimited, remoteAddress: '192.0.2.82')
            ->postJson('/api/admin/wallboard-live-stream/configuration', $this->enabledPayload())
            ->assertTooManyRequests();
    }

    /** @return array{enabled: bool, public_host: string, bind_address: string, rtmps_port: int, tls_certificate_path: string, tls_private_key_path: string, stream_key_configured: bool} */
    private function initialConfiguration(): array
    {
        return [
            'enabled' => false,
            'public_host' => '',
            'bind_address' => '0.0.0.0',
            'rtmps_port' => 1936,
            'tls_certificate_path' => '',
            'tls_private_key_path' => '',
            'stream_key_configured' => false,
        ];
    }

    /** @return array{enabled: bool, public_host: string, rtmps_bind_address: string, rtmps_port: int, tls_certificate_path: string, tls_private_key_path: string, configuration_revision: string} */
    private function enabledPayload(): array
    {
        return [
            'enabled' => true,
            'public_host' => 'stream.example.test',
            'rtmps_bind_address' => '0.0.0.0',
            'rtmps_port' => 1936,
            'tls_certificate_path' => '/etc/letsencrypt/live/stream.example.test/fullchain.pem',
            'tls_private_key_path' => '/etc/letsencrypt/live/stream.example.test/privkey.pem',
            'configuration_revision' => self::INITIAL_CONFIG_SHA256,
        ];
    }

    /** @param array{enabled: bool, public_host: string, bind_address: string, rtmps_port: int, tls_certificate_path: string, tls_private_key_path: string, stream_key_configured: bool} $configuration */
    private function writeManagedEnvironment(array $configuration, ?string $streamKey = null): void
    {
        $contents = implode("\n", [
            'APP_ENV=testing',
            'WALLBOARD_LIVE_STREAM_ENABLED='.($configuration['enabled'] ? 'true' : 'false'),
            'WALLBOARD_LIVE_STREAM_PUBLIC_HOST='.$configuration['public_host'],
            'WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS='.$configuration['bind_address'],
            'WALLBOARD_LIVE_STREAM_RTMPS_PORT='.$configuration['rtmps_port'],
            'WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH='.$configuration['tls_certificate_path'],
            'WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH='.$configuration['tls_private_key_path'],
            'WALLBOARD_LIVE_STREAM_STREAM_KEY='.($streamKey ?? ''),
        ])."\n";
        $this->assertNotFalse(file_put_contents($this->envPath, $contents));
    }

    /** @param array{enabled: bool, public_host: string, bind_address: string, rtmps_port: int, tls_certificate_path: string, tls_private_key_path: string, stream_key_configured: bool} $configuration */
    private function digest(array $configuration): string
    {
        return hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'Stream Configuration Test',
            'first_name' => 'Stream',
            'last_name' => 'Configuration Test',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'stream-configuration-test-'.Str::lower((string) Str::ulid()),
            'display_name' => 'Streamconfiguratietestrol',
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

    private function asWebSession(
        User $user,
        bool $includeCsrf = true,
        string $remoteAddress = '192.0.2.81',
    ): static {
        config([
            'app.url' => 'https://dis.example.test',
            'session.trusted_origins' => ['https://dis.example.test'],
            'sanctum.stateful' => ['dis.example.test'],
        ]);
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->resetRequestState();
        $timestamp = now()->getTimestamp();
        $csrfToken = hash('sha256', 'wallboard-live-stream-configuration-session-'.$user->id);
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
                'REMOTE_ADDR' => $remoteAddress,
            ]);
    }

    private function resetRequestState(): void
    {
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];
    }
}
