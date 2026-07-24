<?php

namespace Tests\Feature;

use App\Console\Commands\RunScheduledBackup;
use App\Http\Controllers\BackupController;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\BackupRequestService;
use App\Services\BackupRuntimeConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class BackupRuntimeConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dis-backup-config-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0750, true));
        $this->configPath = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'backup-config.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_local_runtime_configuration_contains_only_string_base_values(): void
    {
        $this->configureSettings([
            'backup.local_path' => '/legacy/path/that-must-not-be-used',
            'backup.samba.username' => "legacy\nusername",
            'backup.samba.password' => "legacy\tpassword",
        ]);

        $metadata = $this->service()->write('local');

        $config = $this->readConfig();
        self::assertSame([
            'BACKUP_TARGET',
            'BACKUP_ROOT',
            'BACKUP_RETENTION_COUNT',
            'BACKUP_ENCRYPTION_KEY_FILE',
        ], array_keys($config));
        self::assertSame('local', $config['BACKUP_TARGET']);
        self::assertSame(rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/').'/backup', $config['BACKUP_ROOT']);
        self::assertSame('7', $config['BACKUP_RETENTION_COUNT']);
        foreach ($config as $value) {
            self::assertIsString($value);
        }
        self::assertSame([
            'sha256',
            'target',
            'retention_count',
            'target_reference',
        ], array_keys($metadata));
        self::assertSame($this->configFingerprint($config), $metadata['sha256']);
        self::assertSame('local', $metadata['target']);
        self::assertSame(7, $metadata['retention_count']);
        self::assertSame($config['BACKUP_ROOT'], $metadata['target_reference']);
        self::assertSame([], glob($this->temporaryDirectory.DIRECTORY_SEPARATOR.'.backup-config-*') ?: []);
    }

    public function test_samba_runtime_configuration_contains_valid_string_values(): void
    {
        $this->configureSettings();

        $metadata = $this->service()->write('samba');

        $config = $this->readConfig();
        self::assertSame([
            'BACKUP_TARGET',
            'BACKUP_ROOT',
            'BACKUP_RETENTION_COUNT',
            'BACKUP_ENCRYPTION_KEY_FILE',
            'BACKUP_SAMBA_SHARE',
            'BACKUP_SAMBA_MOUNT',
            'BACKUP_SAMBA_USERNAME',
            'BACKUP_SAMBA_PASSWORD',
            'BACKUP_SAMBA_DOMAIN',
            'BACKUP_SAMBA_VERSION',
        ], array_keys($config));
        self::assertSame('samba', $config['BACKUP_TARGET']);
        self::assertSame('backup-user', $config['BACKUP_SAMBA_USERNAME']);
        self::assertSame('valid-backup-password', $config['BACKUP_SAMBA_PASSWORD']);
        foreach ($config as $value) {
            self::assertIsString($value);
        }
        self::assertSame($this->configFingerprint($config), $metadata['sha256']);
        self::assertSame('samba', $metadata['target']);
        self::assertSame(7, $metadata['retention_count']);
        self::assertSame('//backup.example.test/dis', $metadata['target_reference']);
    }

    public function test_control_characters_are_rejected_before_publication(): void
    {
        $this->configureSettings(['backup.samba.password' => "secret\nvalue"]);

        try {
            $this->service()->write('samba');
            self::fail('A runtime configuration containing control characters was published.');
        } catch (RuntimeException $exception) {
            self::assertSame('Backup runtime configuration contains an invalid value.', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->configPath);
        self::assertSame([], glob($this->temporaryDirectory.DIRECTORY_SEPARATOR.'.backup-config-*') ?: []);
    }

    public function test_controller_and_scheduler_use_the_shared_runtime_configuration_service(): void
    {
        $controllerConstructor = (new ReflectionClass(BackupController::class))->getConstructor();
        self::assertNotNull($controllerConstructor);
        $controllerTypes = array_map(
            static fn ($parameter): ?string => $parameter->getType()?->getName(),
            $controllerConstructor->getParameters(),
        );

        $schedulerTypes = array_map(
            static fn ($parameter): ?string => $parameter->getType()?->getName(),
            (new ReflectionMethod(RunScheduledBackup::class, 'handle'))->getParameters(),
        );

        self::assertContains(BackupRuntimeConfigService::class, $controllerTypes);
        self::assertContains(BackupRuntimeConfigService::class, $schedulerTypes);
        self::assertFalse((new ReflectionClass(BackupController::class))->hasMethod('writeRuntimeConfig'));
        self::assertFalse((new ReflectionClass(RunScheduledBackup::class))->hasMethod('writeRuntimeConfig'));
    }

    public function test_admin_settings_reject_control_characters_without_echoing_values(): void
    {
        $this->app->instance(BackupRuntimeConfigService::class, $this->service());
        $actor = $this->administrator();
        $request = $this->asAdminClient($actor);
        $payload = [
            'target' => 'local',
            'samba_server' => '',
            'samba_share_name' => '',
            'samba_mount' => '/mnt/dis-backup',
            'samba_username' => 'backup-user',
            'samba_password' => '',
            'samba_domain' => '',
            'samba_version' => '3.1.1',
            'auto_enabled' => false,
            'auto_frequency' => 'daily',
            'auto_day_of_week' => 1,
            'auto_time' => '02:15',
            'retention_count' => 7,
        ];

        foreach ([
            'samba_share_name' => "do-not-leak-share\nvalue",
            'samba_share' => "do-not-leak-share-path\nvalue",
            'samba_username' => "do-not-leak-username\nvalue",
            'samba_password' => "do-not-leak-password\tvalue",
            'samba_domain' => "do-not-leak-domain\rvalue",
        ] as $field => $invalidValue) {
            $response = $request->patchJson('/api/admin/backups/settings', array_replace($payload, [$field => $invalidValue]));
            $response->assertStatus(422);
            self::assertStringNotContainsString($invalidValue, $response->getContent());
            self::assertStringNotContainsString('do-not-leak', $response->getContent());
        }
    }

    public function test_manual_local_backup_ignores_invalid_legacy_samba_values(): void
    {
        $this->configureSettings([
            'backup.target' => 'local',
            'backup.samba.username' => "legacy\nusername",
            'backup.samba.password' => "legacy\tpassword",
        ]);
        $this->app->instance(BackupRuntimeConfigService::class, $this->service());

        $requestRoot = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'requests';
        self::assertTrue(mkdir($requestRoot, 0750));
        $requestId = str_repeat('a', 32);
        $this->app->instance(BackupRequestService::class, new BackupRequestService(
            $requestRoot,
            static fn (): string => $requestId,
            null,
            static function (int $microseconds) use ($requestRoot, $requestId): void {
                unset($microseconds);
                @unlink($requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending');
                file_put_contents(
                    $requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result',
                    json_encode([
                        'exit_code' => 0,
                        'output' => 'Backup created and verified.',
                    ], JSON_THROW_ON_ERROR)."\n",
                );
            },
        ));

        try {
            $response = $this->asAdminClient($this->administrator())
                ->postJson('/api/admin/backups', ['target' => 'local']);

            $response->assertCreated()
                ->assertJsonPath('data.state', 'succeeded')
                ->assertJsonPath('data.request_id', $requestId);
            self::assertSame([
                'BACKUP_TARGET',
                'BACKUP_ROOT',
                'BACKUP_RETENTION_COUNT',
                'BACKUP_ENCRYPTION_KEY_FILE',
            ], array_keys($this->readConfig()));
        } finally {
            foreach (glob($requestRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            rmdir($requestRoot);
        }
    }

    public function test_manual_retention_requires_an_explicit_target_and_returns_the_refreshed_list(): void
    {
        $this->configureSettings([
            'backup.target' => 'local',
            'backup.retention_count' => 2,
        ]);
        $this->app->instance(BackupRuntimeConfigService::class, $this->service());

        $requestRoot = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'prune-requests';
        self::assertTrue(mkdir($requestRoot, 0750));
        $requestId = str_repeat('e', 32);
        $requestPayload = null;
        $this->app->instance(BackupRequestService::class, new BackupRequestService(
            $requestRoot,
            static fn (): string => $requestId,
            null,
            static function (int $microseconds) use ($requestRoot, $requestId, &$requestPayload): void {
                unset($microseconds);
                $pending = $requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
                $contents = file_get_contents($pending);
                self::assertIsString($contents);
                $requestPayload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                @unlink($pending);
                SystemSetting::query()
                    ->where('key', 'backup.retention_count')
                    ->firstOrFail()
                    ->forceFill(['value' => 99])
                    ->save();
                file_put_contents(
                    $requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result',
                    json_encode([
                        'exit_code' => 0,
                        'output' => 'Backup retention applied to local target.',
                    ], JSON_THROW_ON_ERROR)."\n",
                );
            },
        ));

        try {
            $actor = $this->administrator();
            $request = $this->asAdminClient($actor);

            $request->postJson('/api/admin/backups/prune', [])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['details' => ['target']]]);

            $response = $request->postJson('/api/admin/backups/prune', ['target' => 'local']);
            $response->assertOk()
                ->assertJsonPath('data.state', 'succeeded')
                ->assertJsonPath('data.target', 'local')
                ->assertJsonPath(
                    'data.target_reference',
                    rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/').'/backup',
                )
                ->assertJsonPath('data.retention_count', 2)
                ->assertJsonPath('data.request_id', $requestId)
                ->assertJsonStructure(['data' => ['output', 'backups']]);

            self::assertSame('prune', $requestPayload['operation'] ?? null);
            self::assertSame('local', $requestPayload['target'] ?? null);
            self::assertNull($requestPayload['backup_path'] ?? null);
            self::assertSame((string) $actor->id, $requestPayload['actor_id'] ?? null);
            self::assertSame(
                $this->configFingerprint($this->readConfig()),
                $requestPayload['runtime_config_sha256'] ?? null,
            );
            self::assertArrayNotHasKey('BACKUP_SAMBA_PASSWORD', $requestPayload);

            $audit = AuditLog::query()
                ->where('action', 'backups.retention_applied')
                ->sole();
            self::assertSame('local', $audit->metadata['target'] ?? null);
            self::assertSame(
                rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/').'/backup',
                $audit->metadata['target_reference'] ?? null,
            );
            self::assertSame(2, $audit->metadata['retention_count'] ?? null);
            self::assertTrue($audit->metadata['successful'] ?? false);
            self::assertSame($requestId, $audit->metadata['operation_request_id'] ?? null);
            self::assertNotSame($requestId, $audit->metadata['request_id'] ?? null);
            self::assertArrayNotHasKey('sha256', $audit->metadata);
            self::assertArrayNotHasKey('runtime_config_sha256', $audit->metadata);
            self::assertSame(99, SystemSetting::integer('backup.retention_count', 0));
        } finally {
            foreach (glob($requestRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            rmdir($requestRoot);
        }
    }

    public function test_manual_retention_requires_the_existing_backup_manage_permission(): void
    {
        $user = User::query()->create([
            'name' => 'Backup viewer',
            'first_name' => 'Backup',
            'last_name' => 'Viewer',
            'email' => 'backup-viewer@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->asAdminClient($user)
            ->postJson('/api/admin/backups/prune', ['target' => 'local'])
            ->assertForbidden();
    }

    private function service(): BackupRuntimeConfigService
    {
        return new BackupRuntimeConfigService($this->configPath);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function configFingerprint(array $config): string
    {
        $stream = '';
        foreach ($config as $key => $value) {
            self::assertIsString($value);
            $stream .= $key."\0".$value."\0";
        }

        return hash('sha256', $stream);
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(): array
    {
        $contents = file_get_contents($this->configPath);
        self::assertIsString($contents);
        $config = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($config);

        return $config;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureSettings(array $overrides = []): void
    {
        $settings = $overrides + [
            'backup.local_path' => '/opt/dis-data/backup',
            'backup.retention_count' => 7,
            'backup.samba.share' => '//backup.example.test/dis',
            'backup.samba.mount' => '/mnt/dis-backup',
            'backup.samba.username' => 'backup-user',
            'backup.samba.password' => 'valid-backup-password',
            'backup.samba.domain' => 'DIS',
            'backup.samba.version' => '3.1.1',
        ];

        foreach ($settings as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'is_sensitive' => $key === 'backup.samba.password',
                    'updated_by' => null,
                ],
            );
        }
    }

    private function administrator(): User
    {
        $user = User::query()->create([
            'name' => 'Backup configuration administrator',
            'first_name' => 'Backup',
            'last_name' => 'Administrator',
            'email' => 'backup-config-admin@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'backup-config-admin',
            'display_name' => 'Backup configuration administrator',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'backups.manage'],
            [
                'category' => 'backups',
                'display_name' => 'Backups beheren',
                'description' => 'Backups beheren.',
            ],
        );
        $role->permissions()->attach($permission->id, ['created_at' => now()]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Backup configuration test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
