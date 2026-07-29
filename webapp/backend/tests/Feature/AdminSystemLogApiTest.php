<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminSystemLogApiTest extends TestCase
{
    use RefreshDatabase;

    private string $logRoot;

    private string $outsideLog;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = str()->lower((string) str()->ulid());
        $this->logRoot = storage_path('framework/testing/system-logs-'.$suffix);
        $this->outsideLog = storage_path('framework/testing/system-logs-outside-'.$suffix.'.log');
        File::makeDirectory($this->logRoot, 0750, true);
        config()->set('dis.system_logs.directory', $this->logRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logRoot);
        File::delete($this->outsideLog);

        parent::tearDown();
    }

    public function test_logs_require_web_authentication_completed_two_factor_and_dedicated_permission(): void
    {
        $this->getJson('/api/admin/system/logs')->assertUnauthorized();

        $withoutPermission = $this->user('logs-denied@example.test');
        $this->asAdminClient($withoutPermission)
            ->getJson('/api/admin/system/logs')
            ->assertForbidden();

        $healthAndAuditViewer = $this->user('logs-health@example.test', [
            'system.health.view',
            'audit.view',
        ]);
        $this->asAdminClient($healthAndAuditViewer)
            ->getJson('/api/admin/system/logs')
            ->assertForbidden();

        $viewer = $this->user('logs-pending-2fa@example.test', ['system.logs.view']);
        $pendingToken = $viewer->createToken(
            'Pending system logs admin test',
            ['2fa:pending', 'client:web'],
            now()->addMinutes(10),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$pendingToken)
            ->getJson('/api/admin/system/logs')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $nativeToken = $viewer->createToken(
            'Native client cannot read system logs',
            ['*', 'client:operator'],
            now()->addMinutes(10),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$nativeToken)
            ->getJson('/api/admin/system/logs')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'web_client_required');
    }

    public function test_index_exposes_only_safe_laravel_log_sources_without_server_paths(): void
    {
        File::put($this->logRoot.'/laravel-2026-07-28.log', "ouder\n");
        File::put($this->logRoot.'/laravel-2026-07-29.log', "nieuw\n");
        File::put($this->logRoot.'/custom.log', "niet tonen\n");
        File::put($this->logRoot.'/laravel.txt', "niet tonen\n");
        File::put($this->outsideLog, "buiten de logmap\n");

        $symlinkCreated = @symlink($this->outsideLog, $this->logRoot.'/laravel-2026-07-27.log');
        $hardlinkCreated = @link($this->outsideLog, $this->logRoot.'/laravel-2026-07-26.log');
        $viewer = $this->user('logs-index@example.test', ['system.logs.view']);

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/system/logs')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'data.logs')
            ->assertJsonPath('data.logs.0.name', 'laravel-2026-07-29.log')
            ->assertJsonPath('data.logs.1.name', 'laravel-2026-07-28.log');

        $serialized = (string) $response->getContent();
        $this->assertStringNotContainsString($this->logRoot, $serialized);
        $this->assertStringNotContainsString($this->outsideLog, $serialized);
        $this->assertStringNotContainsString('custom.log', $serialized);
        if ($symlinkCreated) {
            $this->assertStringNotContainsString('laravel-2026-07-27.log', $serialized);
        }
        if ($hardlinkCreated) {
            $this->assertStringNotContainsString('laravel-2026-07-26.log', $serialized);
        }

        $audit = AuditLog::query()->where('action', 'system.logs_listed')->sole();
        $this->assertSame($viewer->id, $audit->actor_id);
        $this->assertSame(['log_count' => 2, 'request_id' => $audit->metadata['request_id']], $audit->metadata);
    }

    public function test_initial_tail_is_redacted_bounded_and_incremental_reads_return_only_complete_new_lines(): void
    {
        $path = $this->logRoot.'/laravel-2026-07-29.log';
        File::put($path, implode("\n", [
            '[2026-07-29 12:00:00] production.INFO: Zichtbare regel',
            'Authorization: Bearer bearer-secret',
            'X-DIS-Developer-Key: developer-secret',
            'password=plain-password-secret',
            '-----BEGIN PRIVATE KEY-----',
            'private-key-secret',
            '-----END PRIVATE KEY-----',
            "\e[31mGekleurde tekst\e[0m met\x07control",
            "Voor bidi \u{202E}veilig",
            "Markeringen \u{061C}A\u{200E}B\u{200F}C\u{2028}D",
            str_repeat('lange regel ', 500),
        ])."\nNog niet compleet");
        $viewer = $this->user('logs-tail@example.test', ['system.logs.view']);
        $client = $this->asAdminClient($viewer);

        $initial = $client
            ->getJson('/api/admin/system/logs/laravel-2026-07-29.log?lines=200')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.name', 'laravel-2026-07-29.log')
            ->assertJsonPath('data.reset', false)
            ->assertJsonPath('data.poll_after_ms', 2000);

        $content = (string) $initial->getContent();
        foreach ([
            'bearer-secret',
            'developer-secret',
            'plain-password-secret',
            'private-key-secret',
            'Nog niet compleet',
            "\e[31m",
            "\x07",
            $this->logRoot,
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $content);
        }
        $this->assertStringContainsString('Zichtbare regel', $content);
        $this->assertStringContainsString('[REDACTED]', $content);
        $this->assertStringContainsString('[REDACTED PRIVATE KEY]', $content);
        $this->assertStringContainsString('Gekleurde tekst metcontrol', $content);
        $this->assertStringContainsString('Voor bidi veilig', $content);
        $this->assertStringContainsString('Markeringen ABC D', $content);
        $this->assertStringNotContainsString("\u{202E}", $content);
        $this->assertStringNotContainsString("\u{061C}", $content);
        $this->assertStringContainsString('[regel afgekapt]', $content);
        $this->assertLessThan(filesize($path), (int) $initial->json('data.cursor'));

        $openAudit = AuditLog::query()->where('action', 'system.log_view_started')->sole();
        $this->assertSame($viewer->id, $openAudit->actor_id);
        $this->assertSame('laravel-2026-07-29.log', $openAudit->metadata['filename']);
        $this->assertArrayNotHasKey('lines', $openAudit->metadata);
        $this->assertStringNotContainsString('Zichtbare regel', json_encode($openAudit->toArray(), JSON_THROW_ON_ERROR));

        File::append($path, " en nu wel\nNieuwe incrementele regel\n");
        $cursor = (int) $initial->json('data.cursor');
        $generation = (string) $initial->json('data.generation');
        $checkpoint = (string) $initial->json('data.checkpoint');
        $incremental = $client
            ->getJson('/api/admin/system/logs/laravel-2026-07-29.log?cursor='.$cursor.'&generation='.$generation.'&checkpoint='.$checkpoint)
            ->assertOk()
            ->assertJsonPath('data.reset', false)
            ->assertJsonPath('data.lines.0', 'Nog niet compleet en nu wel')
            ->assertJsonPath('data.lines.1', 'Nieuwe incrementele regel');

        $this->assertGreaterThan($cursor, (int) $incremental->json('data.cursor'));
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'system.log_view_started')->count(),
            'Realtime vervolgrequests mogen de auditlog niet iedere twee seconden vullen.',
        );
    }

    public function test_truncation_resets_the_cursor_and_returns_a_fresh_tail(): void
    {
        $path = $this->logRoot.'/laravel.log';
        File::put($path, str_repeat("oude-regel\n", 100));
        $viewer = $this->user('logs-rotation@example.test', ['system.logs.view']);
        $client = $this->asAdminClient($viewer);
        $initial = $client->getJson('/api/admin/system/logs/laravel.log')->assertOk();

        File::put($path, "verse-regel\n");
        $cursor = (int) $initial->json('data.cursor');
        $generation = (string) $initial->json('data.generation');
        $checkpoint = (string) $initial->json('data.checkpoint');
        $client
            ->getJson('/api/admin/system/logs/laravel.log?cursor='.$cursor.'&generation='.$generation.'&checkpoint='.$checkpoint)
            ->assertOk()
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.reset_reason', 'truncated')
            ->assertJsonPath('data.lines.0', 'verse-regel');
    }

    public function test_latest_source_follows_daily_rotation_and_audits_the_new_view(): void
    {
        File::put($this->logRoot.'/laravel-2026-07-28.log', "oude dag\n");
        $viewer = $this->user('logs-latest@example.test', ['system.logs.view']);
        $client = $this->asAdminClient($viewer);
        $initial = $client
            ->getJson('/api/admin/system/logs/latest')
            ->assertOk()
            ->assertJsonPath('data.name', 'laravel-2026-07-28.log');

        File::put($this->logRoot.'/laravel-2026-07-29.log', "nieuwe dag\n");
        $client
            ->getJson('/api/admin/system/logs/latest?cursor='
                .$initial->json('data.cursor')
                .'&generation='
                .$initial->json('data.generation')
                .'&checkpoint='
                .$initial->json('data.checkpoint'))
            ->assertOk()
            ->assertJsonPath('data.name', 'laravel-2026-07-29.log')
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.reset_reason', 'rotated')
            ->assertJsonPath('data.lines.0', 'nieuwe dag');

        $this->assertSame(
            2,
            AuditLog::query()->where('action', 'system.log_view_started')->count(),
        );
    }

    public function test_checkpoint_detects_copytruncate_after_the_file_regrows_past_the_cursor(): void
    {
        $path = $this->logRoot.'/laravel.log';
        $stableTail = str_repeat("zelfde-context-regel\n", 40);
        File::put($path, "prefix-AAAA\n".str_repeat("zelfde-vulling\n", 100).$stableTail);
        $viewer = $this->user('logs-copytruncate@example.test', ['system.logs.view']);
        $client = $this->asAdminClient($viewer);
        $initial = $client->getJson('/api/admin/system/logs/laravel.log')->assertOk();

        File::put($path, "prefix-BBBB\n".str_repeat("zelfde-vulling\n", 100).$stableTail);
        $client
            ->getJson('/api/admin/system/logs/laravel.log?cursor='
                .$initial->json('data.cursor')
                .'&generation='
                .$initial->json('data.generation')
                .'&checkpoint='
                .$initial->json('data.checkpoint'))
            ->assertOk()
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.reset_reason', 'replaced')
            ->assertJsonPath('data.lines.0', 'prefix-BBBB');
    }

    public function test_checkpoint_is_bound_to_the_viewer_and_cross_user_replay_is_audited(): void
    {
        File::put($this->logRoot.'/laravel.log', "beveiligde regel\n");
        $firstViewer = $this->user('logs-first-viewer@example.test', ['system.logs.view']);
        $initial = $this->asAdminClient($firstViewer)
            ->getJson('/api/admin/system/logs/laravel.log')
            ->assertOk();

        $secondViewer = $this->user('logs-second-viewer@example.test', ['system.logs.view']);
        $this->asAdminClient($secondViewer)
            ->getJson('/api/admin/system/logs/laravel.log?cursor='
                .$initial->json('data.cursor')
                .'&generation='
                .$initial->json('data.generation')
                .'&checkpoint='
                .$initial->json('data.checkpoint'))
            ->assertOk()
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.reset_reason', 'replaced')
            ->assertJsonPath('data.lines.0', 'beveiligde regel');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $firstViewer->id,
            'action' => 'system.log_view_started',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $secondViewer->id,
            'action' => 'system.log_view_started',
        ]);
    }

    public function test_private_key_fragments_are_redacted_across_chunk_boundaries(): void
    {
        $path = $this->logRoot.'/laravel.log';
        $keyLine = str_repeat('A', 64);
        $shortKeyLine = 'QUJDRA==';
        File::put(
            $path,
            "-----BEGIN PRIVATE KEY-----\n"
                .str_repeat($keyLine."\n", 2500)
                .$shortKeyLine."\n"
                ."-----END PRIVATE KEY-----\nzichtbaar na sleutel\n",
        );
        $viewer = $this->user('logs-pem-boundary@example.test', ['system.logs.view']);
        $client = $this->asAdminClient($viewer);

        $initial = $client
            ->getJson('/api/admin/system/logs/laravel.log')
            ->assertOk();
        $this->assertStringNotContainsString($keyLine, (string) $initial->getContent());
        $this->assertStringNotContainsString($shortKeyLine, (string) $initial->getContent());
        $this->assertStringContainsString('zichtbaar na sleutel', (string) $initial->getContent());

        File::put(
            $path,
            "start\n-----BEGIN PRIVATE KEY-----\n".$keyLine."\ngewone regel na onvolledig blok\n",
        );
        $partial = $client->getJson('/api/admin/system/logs/laravel.log')->assertOk();
        $this->assertStringContainsString(
            'gewone regel na onvolledig blok',
            (string) $partial->getContent(),
        );
        File::append(
            $path,
            str_repeat('B', 64)."\n".$shortKeyLine."\n-----END PRIVATE KEY-----\nzichtbaar vervolg\n",
        );
        $continued = $client
            ->getJson('/api/admin/system/logs/laravel.log?cursor='
                .$partial->json('data.cursor')
                .'&generation='
                .$partial->json('data.generation')
                .'&checkpoint='
                .$partial->json('data.checkpoint'))
            ->assertOk();

        $continuedContent = (string) $continued->getContent();
        $this->assertStringNotContainsString(str_repeat('B', 64), $continuedContent);
        $this->assertStringNotContainsString($shortKeyLine, $continuedContent);
        $this->assertStringContainsString('zichtbaar vervolg', $continuedContent);
    }

    public function test_invalid_sources_and_cursor_parameters_are_rejected(): void
    {
        File::put($this->logRoot.'/laravel.log', "regel\n");
        $viewer = $this->user('logs-validation@example.test', ['system.logs.view']);
        $client = $this->asAdminClient($viewer);

        $client->getJson('/api/admin/system/logs/custom.log')->assertNotFound();
        $client->getJson('/api/admin/system/logs/laravel.log?lines=1001')
            ->assertUnprocessable();
        $client->getJson('/api/admin/system/logs/laravel.log?cursor=1')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['generation']]]);
        $client->getJson('/api/admin/system/logs/laravel.log?cursor=-1&generation='.str_repeat('a', 64).'&checkpoint='.str_repeat('b', 64))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['cursor']]]);
    }

    public function test_system_log_polling_has_a_dedicated_per_client_limit(): void
    {
        File::put($this->logRoot.'/laravel.log', "regel\n");
        $viewer = $this->user('logs-limit@example.test', ['system.logs.view']);
        $client = $this->asAdminClient($viewer);
        $initial = $client->getJson('/api/admin/system/logs/laravel.log')->assertOk();
        $path = '/api/admin/system/logs/laravel.log?cursor='
            .$initial->json('data.cursor')
            .'&generation='
            .$initial->json('data.generation')
            .'&checkpoint='
            .$initial->json('data.checkpoint');

        for ($attempt = 2; $attempt <= 60; $attempt++) {
            $client->getJson($path)->assertOk();
        }

        $client->getJson($path)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_permission_migration_is_idempotent_and_grants_only_system_administrator(): void
    {
        $administrator = $this->role('system-administrator');
        $coordinator = $this->role('national-coordinator');
        $auditor = $this->role('auditor');
        $permission = Permission::query()->where('name', 'system.logs.view')->first();
        if ($permission !== null) {
            DB::table('permission_role')->where('permission_id', $permission->id)->delete();
            $permission->delete();
        }

        $migration = require database_path('migrations/2026_07_29_000001_add_system_logs_view_permission.php');
        $migration->up();
        $migration->up();

        $permissionId = Permission::query()->where('name', 'system.logs.view')->value('id');
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $administrator->id,
            'permission_id' => $permissionId,
        ]);
        foreach ([$coordinator, $auditor] as $role) {
            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }
        $this->assertSame(
            1,
            DB::table('permission_role')
                ->where('role_id', $administrator->id)
                ->where('permission_id', $permissionId)
                ->count(),
        );
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'System Log Test User',
            'first_name' => 'System Log',
            'last_name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = $this->role('logs-test-'.str()->lower((string) str()->ulid()));
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'System log test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function role(string $name): Role
    {
        return Role::query()->create([
            'name' => $name,
            'display_name' => $name,
            'can_use_admin_app' => true,
            'can_use_operator_app' => true,
        ]);
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken(
            'System logs webbeheer test',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
