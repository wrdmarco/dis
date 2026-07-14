<?php

namespace Tests\Feature;

use App\Casts\SystemSettingValueCast;
use App\Models\Permission;
use App\Mail\UserPasswordRecoveryMail;
use App\Mail\UserWelcomeMail;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DeveloperAccessService;
use App\Services\TwoFactorService;
use App\Services\UserService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class IamSecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_app_access_requires_mfa_even_when_global_setting_is_disabled(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => TwoFactorService::REQUIRED_KEY],
            ['value' => false, 'is_sensitive' => false],
        );
        $user = $this->user();
        $adminRole = Role::query()->create([
            'name' => 'test-admin',
            'display_name' => 'Test admin',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $user->roles()->attach($adminRole->id, ['created_at' => now()]);

        $this->assertTrue(app(TwoFactorService::class)->isRequiredFor($user));
    }

    public function test_sensitive_system_setting_is_encrypted_in_database(): void
    {
        SystemSetting::query()->create([
            'key' => 'mail.password',
            'value' => 'smtp-secret-value',
            'is_sensitive' => false,
        ]);

        $rawValue = (string) DB::table('system_settings')->where('key', 'mail.password')->value('value');
        $this->assertStringNotContainsString('smtp-secret-value', $rawValue);
        $this->assertStringContainsString(SystemSettingValueCast::ENVELOPE_KEY, $rawValue);
        $this->assertSame('smtp-secret-value', SystemSetting::string('mail.password'));
        $this->assertTrue((bool) SystemSetting::query()->findOrFail('mail.password')->is_sensitive);
    }

    public function test_audit_metadata_recursively_redacts_credentials_and_tokens(): void
    {
        $log = app(AuditService::class)->record('security.test', 'iam', null, [
            'password' => 'secret',
            'nested' => [
                'authorization_header' => 'Bearer token',
                'safe_value' => 'visible',
            ],
        ]);

        $this->assertSame('[REDACTED]', $log->metadata['password']);
        $this->assertSame('[REDACTED]', $log->metadata['nested']['authorization_header']);
        $this->assertSame('visible', $log->metadata['nested']['safe_value']);
    }

    public function test_system_administrator_receives_dedicated_delete_permissions(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $permissionNames = Role::query()
            ->where('name', Role::SYSTEM_ADMINISTRATOR)
            ->firstOrFail()
            ->permissions()
            ->pluck('name');

        $this->assertTrue($permissionNames->contains('users.delete'));
        $this->assertTrue($permissionNames->contains('roles.delete'));
    }

    public function test_email_change_revokes_reset_tokens_for_old_and_new_addresses(): void
    {
        $target = $this->user();
        $actor = User::query()->create([
            'name' => 'Credential Manager',
            'first_name' => 'Credential',
            'last_name' => 'Manager',
            'email' => 'credential-manager@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'users.credentials.manage'],
            [
                'category' => 'security-test',
                'display_name' => 'Manage user credentials',
                'description' => 'Security contract test permission',
            ],
        );
        $role = Role::query()->create([
            'name' => 'credential-manager',
            'display_name' => 'Credential manager',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $role->permissions()->attach($permission->id, ['created_at' => now()]);
        $actor->roles()->attach($role->id, ['created_at' => now()]);

        $newEmail = 'security-renamed@example.test';
        DB::table('password_reset_tokens')->insert([
            ['email' => $target->email, 'token' => Hash::make('old-token'), 'created_at' => now()],
            ['email' => $newEmail, 'token' => Hash::make('new-token'), 'created_at' => now()],
        ]);
        $pairingId = (string) str()->ulid();
        DB::table('mobile_pairing_codes')->insert([
            'id' => $pairingId,
            'user_id' => $target->id,
            'code_hash' => hash('sha256', 'pending-pairing-code'),
            'client_type' => 'operator',
            'expires_at' => now()->addSeconds(30),
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(UserService::class)->update($target, ['email' => $newEmail], $actor);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'security@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $newEmail]);
        $this->assertDatabaseMissing('mobile_pairing_codes', ['id' => $pairingId]);
    }

    public function test_invalid_developer_key_returns_the_intended_unauthorized_response(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'developer.android_upload'],
            [
                'value' => [
                    'enabled' => true,
                    'key_hash' => hash('sha256', 'correct-developer-key'),
                    'allowed_ips' => [],
                    'scopes' => [DeveloperAccessService::SCOPE_LOGS_READ],
                ],
                'is_sensitive' => true,
            ],
        );

        $this->withHeader('X-DIS-Developer-Key', 'incorrect-developer-key')
            ->getJson('/api/developer/logs')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'developer_api_invalid_key')
            ->assertJsonPath('error.message', 'Invalid developer API key.');
    }

    public function test_recovery_code_cannot_be_consumed_twice_from_stale_user_instances(): void
    {
        $user = $this->user();
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['ATOMIC-12345'],
            'two_factor_confirmed_at' => now(),
        ])->save();
        $firstReader = User::query()->findOrFail($user->id);
        $staleReader = User::query()->findOrFail($user->id);

        $this->assertTrue(app(TwoFactorService::class)->consumeRecoveryCode($firstReader, 'ATOMIC-12345'));
        $this->assertFalse(app(TwoFactorService::class)->consumeRecoveryCode($staleReader, 'ATOMIC-12345'));
        $this->assertSame([], $user->refresh()->two_factor_recovery_codes);
    }

    public function test_new_user_receives_activation_link_without_admin_supplied_password(): void
    {
        Mail::fake();
        $actor = $this->user();

        $created = app(UserService::class)->create([
            'first_name' => 'Nieuwe',
            'last_name' => 'Gebruiker',
            'email' => 'nieuw@example.test',
            'account_status' => 'active',
        ], $actor);

        Mail::assertSent(UserWelcomeMail::class, fn (UserWelcomeMail $mail): bool => $mail->hasTo('nieuw@example.test'));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'nieuw@example.test']);
        $this->assertFalse(Hash::check('Test-password-123!', (string) $created->password));
    }

    public function test_admin_password_recovery_sends_link_without_changing_password(): void
    {
        Mail::fake();
        $target = $this->user();
        $actor = User::query()->create([
            'name' => 'Beheerder', 'first_name' => 'Beheer', 'last_name' => 'Der',
            'email' => 'beheerder@example.test', 'password' => 'Actor-password-123!', 'account_status' => 'active',
        ]);
        $passwordHash = $target->password;

        app(UserService::class)->sendPasswordRecovery($target, $actor);

        Mail::assertSent(UserPasswordRecoveryMail::class, fn (UserPasswordRecoveryMail $mail): bool => $mail->hasTo($target->email));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $target->email]);
        $this->assertSame($passwordHash, $target->refresh()->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.password_recovery_sent', 'target_id' => $target->id]);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Security Test',
            'first_name' => 'Security',
            'last_name' => 'Test',
            'email' => 'security@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
        ]);
    }
}
