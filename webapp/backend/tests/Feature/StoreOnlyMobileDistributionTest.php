<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWebCsrfToken;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DeveloperAccessService;
use App\Services\WebSessionService;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StoreOnlyMobileDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_exposes_only_operator_store_links(): void
    {
        $this->withoutMiddleware(VerifyWebCsrfToken::class);
        config([
            'app.url' => 'https://dis.example.test',
            'session.trusted_origins' => ['https://dis.example.test'],
            'sanctum.stateful' => ['dis.example.test'],
        ]);

        foreach ([
            'software.download.operator_android.app_store_url' => 'https://play.google.com/store/apps/details?id=nl.wrdmarco.dis',
            'software.download.operator_ios.app_store_url' => 'https://apps.apple.com/app/id1234567890',
            'software.download.operator_android.source' => 'direct',
            'software.download.admin_android.app_store_url' => 'https://example.test/retired-admin-app',
        ] as $key => $value) {
            SystemSetting::query()->create([
                'key' => $key,
                'value' => $value,
                'is_sensitive' => false,
            ]);
        }

        $user = User::query()->create([
            'name' => 'Store Registration Test',
            'first_name' => 'Store',
            'last_name' => 'Registration Test',
            'email' => 'store-registration@example.test',
            'password' => Hash::make('Temporary-password-123!'),
            'account_status' => 'active',
        ]);

        $response = $this->withSession([
            WebSessionService::KEY_PENDING_USER_ID => $user->id,
            WebSessionService::KEY_PENDING_PURPOSE => WebSessionService::PURPOSE_REGISTRATION_ACCOUNT,
            WebSessionService::KEY_PENDING_EXPIRES_AT => now()->addMinutes(30)->getTimestamp(),
            WebSessionService::KEY_PENDING_VERSION => (int) $user->auth_session_version,
        ])->withServerVariables([
            'HTTP_HOST' => 'dis.example.test',
            'HTTPS' => 'on',
        ])->withHeaders([
            'Origin' => 'https://dis.example.test',
            'Referer' => 'https://dis.example.test/register',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->postJson('/api/registration/invite');

        $response->assertOk();
        $this->assertSame([
            'operator_android' => [
                'app_store_url' => 'https://play.google.com/store/apps/details?id=nl.wrdmarco.dis',
            ],
            'operator_ios' => [
                'app_store_url' => 'https://apps.apple.com/app/id1234567890',
            ],
        ], $response->json('data.download_options.channels'));
    }

    public function test_legacy_mobile_update_routes_are_gone(): void
    {
        foreach ([
            ['GET', 'api/updates/android/current'],
            ['GET', 'api/updates/ios/current'],
            ['GET', 'api/updates/android/{version}/download'],
            ['POST', 'api/developer/android/upload'],
            ['GET', 'api/software/download-options'],
            ['GET', 'api/admin/updates/android'],
            ['POST', 'api/admin/updates/android'],
            ['POST', 'api/admin/updates/android/upload'],
            ['PATCH', 'api/admin/updates/android/{version}'],
            ['GET', 'api/admin/updates/ios'],
            ['POST', 'api/admin/updates/ios'],
            ['PATCH', 'api/admin/updates/ios/{version}'],
        ] as [$method, $uri]) {
            $registered = collect(Route::getRoutes()->getRoutes())->contains(
                static fn ($route): bool => $route->uri() === $uri && in_array($method, $route->methods(), true),
            );
            $this->assertFalse($registered, "Legacy route {$method} {$uri} is still registered.");
        }

        $this->getJson('/api/updates/android/current?version_code=1')->assertNotFound();
        $this->getJson('/api/updates/ios/current?version_code=1')->assertNotFound();
    }

    public function test_android_upload_is_not_a_developer_scope(): void
    {
        $this->assertNotContains('android_upload', DeveloperAccessService::SCOPES);
        $this->assertSame(
            [DeveloperAccessService::SCOPE_LOGS_READ],
            app(DeveloperAccessService::class)->configuredScopes([
                'scopes' => ['android_upload', DeveloperAccessService::SCOPE_LOGS_READ],
            ]),
        );
    }

    public function test_obsolete_registry_and_defaults_are_not_recreated(): void
    {
        $this->assertFalse(Schema::hasTable('app_versions'));

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        $this->assertDatabaseMissing('permissions', ['name' => 'updates.manage']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'updates.android.minimum_supported_version_code']);
        $this->assertDatabaseHas('system_settings', ['key' => 'updates.android.application_id']);
    }

    public function test_cleanup_migration_preserves_store_and_developer_configuration(): void
    {
        $migration = require database_path('migrations/2026_08_01_000002_remove_legacy_mobile_update_registry.php');
        $migration->down();

        foreach ([
            'updates.android.application_id' => 'nl.wrdmarco.dis',
            'updates.android.minimum_supported_version_code' => 123,
            'updates.android.nl.example.minimum_supported_version_code' => 456,
            'software.download.operator_android.source' => 'direct',
            'software.download.admin_android.source' => 'direct',
            'software.download.operator_ios.source' => 'app_store',
            'software.download.operator_android.app_store_url' => 'https://play.google.com/store/apps/details?id=nl.wrdmarco.dis',
            'software.download.operator_ios.app_store_url' => 'https://apps.apple.com/app/id1234567890',
            'software.download.admin_android.app_store_url' => 'https://example.test/retired-admin-app',
        ] as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'is_sensitive' => false],
            );
        }
        SystemSetting::query()->updateOrCreate(
            ['key' => 'developer.android_upload'],
            [
                'value' => [
                    'enabled' => true,
                    'key_hash' => hash('sha256', 'store-only-test-key'),
                    'scopes' => ['android_upload', DeveloperAccessService::SCOPE_LOGS_READ],
                ],
                'is_sensitive' => true,
            ],
        );

        $migration->up();

        $this->assertFalse(Schema::hasTable('app_versions'));
        $this->assertDatabaseMissing('permissions', ['name' => 'updates.manage']);
        foreach ([
            'updates.android.minimum_supported_version_code',
            'updates.android.nl.example.minimum_supported_version_code',
            'software.download.operator_android.source',
            'software.download.admin_android.source',
            'software.download.operator_ios.source',
            'software.download.admin_android.app_store_url',
        ] as $key) {
            $this->assertDatabaseMissing('system_settings', ['key' => $key]);
        }
        foreach ([
            'updates.android.application_id',
            'software.download.operator_android.app_store_url',
            'software.download.operator_ios.app_store_url',
            DeveloperAccessService::SETTING_KEY,
        ] as $key) {
            $this->assertDatabaseHas('system_settings', ['key' => $key]);
        }
        $this->assertDatabaseMissing('system_settings', ['key' => 'developer.android_upload']);
        $this->assertSame(
            [DeveloperAccessService::SCOPE_LOGS_READ],
            SystemSetting::value(DeveloperAccessService::SETTING_KEY)['scopes'] ?? null,
        );
        $storedValue = (string) DB::table('system_settings')
            ->where('key', DeveloperAccessService::SETTING_KEY)
            ->value('value');
        $this->assertStringContainsString('__encrypted_v1', $storedValue);
        $this->assertStringNotContainsString('store-only-test-key', $storedValue);
    }
}
