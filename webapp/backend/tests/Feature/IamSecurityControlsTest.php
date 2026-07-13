<?php

namespace Tests\Feature;

use App\Casts\SystemSettingValueCast;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
