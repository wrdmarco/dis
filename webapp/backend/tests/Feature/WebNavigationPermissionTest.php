<?php

namespace Tests\Feature;

use App\Events\OsrmOperationStatusChanged;
use App\Models\Asset;
use App\Models\Certification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WebNavigationPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_navigation_routes_use_concrete_server_side_permissions(): void
    {
        $expectations = [
            ['GET', 'api/assets', 'permission:assets.view'],
            ['GET', 'api/expiry-overview', 'permission:expiry.view'],
            ['GET', 'api/product-requests', 'permission:product-requests.view'],
            ['POST', 'api/product-requests', 'permission:product-requests.create'],
            ['GET', 'api/product-requests/{productRequest}', 'permission:product-requests.view'],
            ['PATCH', 'api/product-requests/{productRequest}', 'permission:product-requests.update-own,product-requests.update-any'],
            ['PATCH', 'api/product-requests/{productRequest}/status', 'permission:product-requests.resolve'],
            ['GET', 'api/calendar-events', 'permission:calendar.view'],
            ['GET', 'api/calendar-events/team-options', 'permission:calendar.manage'],
            ['POST', 'api/calendar-events', 'permission:calendar.manage'],
            ['PATCH', 'api/calendar-events/{calendarEvent}', 'permission:calendar.manage'],
            ['DELETE', 'api/calendar-events/{calendarEvent}', 'permission:calendar.manage'],
            ['GET', 'api/operational-weather', 'permission:operational-weather.view'],
            ['GET', 'api/operational-weather/radar/{kind}/{snapshot}.png', 'permission:operational-weather.view'],
            ['GET', 'api/uav-forecast', 'permission:uav-forecast.view'],
            ['GET', 'api/vacations', 'permission:vacations.view,vacations.manage'],
            ['GET', 'api/users/{user}/vacations', 'permission:vacations.view,vacations.manage'],
            ['POST', 'api/users/{user}/vacations', 'permission:vacations.manage'],
            ['GET', 'api/admin/pilot-report/form-config', 'permission:forms.manage'],
            ['PATCH', 'api/admin/deployment-form/config', 'permission:forms.manage'],
            ['GET', 'api/admin/settings', 'permission:settings.manage'],
            ['GET', 'api/admin/branding/settings', 'permission:branding.manage'],
            ['GET', 'api/admin/audit-logs', 'permission:audit.view'],
            ['GET', 'api/admin/backups', 'permission:backups.manage'],
            ['GET', 'api/admin/wallboards', 'permission:wallboards.manage'],
            ['GET', 'api/admin/routing/osrm', 'permission:system.routing.view,system.routing.manage'],
            ['GET', 'api/admin/queues', 'permission:system.queues.view,system.queues.manage'],
            ['GET', 'api/admin/health', 'permission:system.health.view'],
        ];

        foreach ($expectations as [$method, $uri, $permissionMiddleware]) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn (IlluminateRoute $candidate): bool => $candidate->uri() === $uri
                    && in_array($method, $candidate->methods(), true));

            $this->assertInstanceOf(IlluminateRoute::class, $route, "$method $uri was not registered.");
            $this->assertContains($permissionMiddleware, $route->gatherMiddleware(), "$method $uri did not enforce $permissionMiddleware.");
        }

        $this->assertNull(
            collect(Route::getRoutes()->getRoutes())
                ->first(fn (IlluminateRoute $candidate): bool => str_starts_with($candidate->uri(), 'api/admin/knmi')),
        );
    }

    public function test_legacy_settings_and_health_permissions_do_not_open_split_management_pages(): void
    {
        $legacyManager = $this->user('legacy-navigation-manager@example.test');
        $this->grant($legacyManager, ['settings.manage', 'system.health.view']);

        foreach ([
            '/api/admin/pilot-report/form-config',
            '/api/admin/branding/settings',
            '/api/admin/routing/osrm',
            '/api/admin/queues',
        ] as $endpoint) {
            $this->asWebClient($legacyManager)
                ->getJson($endpoint)
                ->assertForbidden();
        }

        $this->asWebClient($legacyManager)
            ->getJson('/api/admin/settings')
            ->assertOk();
        $this->asWebClient($legacyManager)
            ->getJson('/api/admin/health')
            ->assertOk();
    }

    public function test_retired_knmi_management_permission_preserves_forecast_access_then_disappears(): void
    {
        $role = Role::query()->create([
            'name' => 'legacy-knmi-manager',
            'display_name' => 'Legacy KNMI manager',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $legacyPermission = Permission::query()->create([
            'name' => 'knmi.manage',
            'display_name' => 'Legacy KNMI management',
            'category' => 'migration-test',
            'description' => 'Compatibility input for retirement.',
        ]);
        $role->permissions()->attach($legacyPermission->id, ['created_at' => now()]);
        $role->permissions()->detach(
            Permission::query()
                ->whereIn('name', ['operational-weather.view', 'uav-forecast.view'])
                ->pluck('id'),
        );

        $migration = require database_path('migrations/2026_07_29_000002_purge_retired_weather_snapshot_metadata.php');
        $migration->up();

        $this->assertDatabaseMissing('permissions', ['name' => 'knmi.manage']);
        $this->assertEqualsCanonicalizing(
            ['operational-weather.view', 'uav-forecast.view'],
            $role->permissions()
                ->whereIn('permissions.name', ['operational-weather.view', 'uav-forecast.view'])
                ->pluck('permissions.name')
                ->all(),
        );
    }

    public function test_routing_viewer_uses_scoped_realtime_channel_without_system_update_access(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        $routingViewer = $this->user('routing-realtime-viewer@example.test');
        $this->grant($routingViewer, ['system.routing.view']);

        $this->asWebClient($routingViewer)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-admin.routing',
            ])
            ->assertOk();

        $this->asWebClient($routingViewer)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-admin.system',
            ])
            ->assertForbidden();

        $healthViewer = $this->user('system-realtime-viewer@example.test');
        $this->grant($healthViewer, ['system.health.view']);
        $this->asWebClient($healthViewer)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-admin.system',
            ])
            ->assertOk();
        $this->asWebClient($healthViewer)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-admin.routing',
            ])
            ->assertForbidden();

        $channelNames = collect((new OsrmOperationStatusChanged([]))->broadcastOn())
            ->map(fn (PrivateChannel $channel): string => $channel->name)
            ->all();

        $this->assertSame(['private-admin.routing'], $channelNames);
    }

    public function test_deployment_manager_can_authorize_the_operations_realtime_channel(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        $deploymentManager = $this->user('deployment-realtime-manager@example.test');
        $this->grant($deploymentManager, ['deployments.manage']);

        $this->asWebClient($deploymentManager)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-operations',
            ])
            ->assertOk();

        $this->asWebClient($deploymentManager)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-deployment-requests',
            ])
            ->assertOk();
    }

    public function test_dedicated_navigation_permissions_open_only_their_intended_read_pages(): void
    {
        $manager = $this->user('dedicated-navigation-manager@example.test');
        $this->grant($manager, [
            'expiry.view',
            'forms.manage',
            'branding.manage',
            'system.routing.view',
            'system.queues.view',
        ]);

        $this->asWebClient($manager)
            ->getJson('/api/expiry-overview')
            ->assertOk()
            ->assertJsonPath('data.assets', [])
            ->assertJsonPath('data.certifications', []);
        $this->asWebClient($manager)
            ->getJson('/api/admin/pilot-report/form-config')
            ->assertOk();
        $this->asWebClient($manager)
            ->getJson('/api/admin/deployment-form/config')
            ->assertOk();
        $this->asWebClient($manager)
            ->getJson('/api/admin/branding/settings')
            ->assertOk();
        $this->asWebClient($manager)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk();
        $this->asWebClient($manager)
            ->getJson('/api/admin/queues')
            ->assertOk();

        $this->asWebClient($manager)
            ->getJson('/api/admin/settings')
            ->assertForbidden();
        $this->asWebClient($manager)
            ->getJson('/api/admin/health')
            ->assertForbidden();
    }

    public function test_branding_permission_cannot_read_or_mutate_unrelated_system_settings(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'app.brand_name'],
            ['value' => 'Oude naam', 'is_sensitive' => false],
        );
        SystemSetting::query()->updateOrCreate(
            ['key' => 'mail.password'],
            ['value' => 'existing-secret', 'is_sensitive' => true],
        );

        $brandingManager = $this->user('branding-manager@example.test');
        $this->grant($brandingManager, ['branding.manage']);

        $this->asWebClient($brandingManager)
            ->getJson('/api/admin/settings')
            ->assertForbidden();

        $this->asWebClient($brandingManager)
            ->getJson('/api/admin/branding/settings')
            ->assertOk()
            ->assertJsonFragment(['key' => 'app.brand_name', 'value' => 'Oude naam'])
            ->assertJsonMissing(['key' => 'mail.password']);

        $this->asWebClient($brandingManager)
            ->patchJson('/api/admin/branding/settings', [
                'settings' => ['app.brand_name' => 'Nieuwe naam'],
            ])
            ->assertOk()
            ->assertJsonFragment(['key' => 'app.brand_name', 'value' => 'Nieuwe naam']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $brandingManager->id,
            'action' => 'branding.settings_updated',
        ]);

        $this->asWebClient($brandingManager)
            ->patchJson('/api/admin/branding/settings', [
                'settings' => [
                    'app.brand_name' => 'Mag niet gedeeltelijk worden opgeslagen',
                    'mail.password' => 'replacement-secret',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame('existing-secret', SystemSetting::value('mail.password'));
        $this->assertSame('Nieuwe naam', SystemSetting::string('app.brand_name'));
    }

    public function test_settings_permission_cannot_read_or_mutate_branding_settings_or_logo(): void
    {
        $brandingKeys = [
            'app.brand_name',
            'app.brand_short_name',
            'app.login_title',
            'app.login_subtitle',
            'app.logo_data_url',
            'mobile.tenant_name',
            'security.mfa_issuer_name',
            'mail.from_name',
            'mail.template.welcome_subject',
            'mail.template.welcome_body',
            'certification.warning_days_before_expiry',
            'mail.template.certification_expiry_subject',
            'mail.template.certification_expiry_body',
            'asset.warning_days_before_expiry',
            'mail.template.asset_expiry_subject',
            'mail.template.asset_expiry_body',
            'push.template.preannouncement_title',
            'push.template.preannouncement_body',
            'push.template.dispatch_title',
            'push.template.dispatch_body',
            'push.template.dispatch_unavailable_escalation_title',
            'push.template.dispatch_unavailable_escalation_body',
            'push.template.additional_info_title',
            'push.template.additional_info_body',
            'push.template.cancellation_title',
            'push.template.cancellation_body',
        ];

        foreach ($brandingKeys as $key) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => 'branding-only-value', 'is_sensitive' => false],
            );
        }
        SystemSetting::query()->updateOrCreate(
            ['key' => 'mail.host'],
            ['value' => 'smtp.old.example.test', 'is_sensitive' => false],
        );

        $settingsManager = $this->user('settings-only-manager@example.test');
        $this->grant($settingsManager, ['settings.manage']);

        $settingsResponse = $this->asWebClient($settingsManager)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonFragment(['key' => 'mail.host', 'value' => 'smtp.old.example.test']);

        foreach ($brandingKeys as $key) {
            $settingsResponse->assertJsonMissing(['key' => $key]);
        }

        $this->asWebClient($settingsManager)
            ->getJson('/api/admin/branding/settings')
            ->assertForbidden();
        $this->asWebClient($settingsManager)
            ->patchJson('/api/admin/branding/settings', [
                'settings' => ['app.brand_name' => 'Niet toegestaan'],
            ])
            ->assertForbidden();
        $this->asWebClient($settingsManager)
            ->postJson('/api/admin/branding/logo')
            ->assertForbidden();
        $this->asWebClient($settingsManager)
            ->deleteJson('/api/admin/branding/logo')
            ->assertForbidden();

        $this->asWebClient($settingsManager)
            ->patchJson('/api/admin/settings', [
                'settings' => [
                    'mail.host' => 'smtp.partial.example.test',
                    'app.brand_name' => 'Niet via algemene instellingen',
                    'app.logo_data_url' => 'data:image/png;base64,blocked',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame('smtp.old.example.test', SystemSetting::string('mail.host'));
        $this->assertSame('branding-only-value', SystemSetting::string('app.brand_name'));
        $this->assertSame('branding-only-value', SystemSetting::string('app.logo_data_url'));

        $this->asWebClient($settingsManager)
            ->patchJson('/api/admin/settings', [
                'settings' => ['mail.host' => 'smtp.new.example.test'],
            ])
            ->assertOk()
            ->assertJsonFragment(['key' => 'mail.host', 'value' => 'smtp.new.example.test'])
            ->assertJsonMissing(['key' => 'app.brand_name'])
            ->assertJsonMissing(['key' => 'app.logo_data_url']);
    }

    public function test_expiry_overview_filters_each_dataset_by_its_underlying_resource_permission(): void
    {
        Asset::query()->create([
            'asset_tag' => 'EXPIRY-RBAC-ASSET',
            'name' => 'Expiry RBAC asset',
            'type' => 'support_equipment',
            'status' => 'ready',
            'maintenance_due_at' => now()->addDays(10)->toDateString(),
        ]);
        $certificationOwner = $this->user('expiry-certification-owner@example.test');
        $certification = Certification::query()->create([
            'code' => 'EXPIRY-RBAC-CERT',
            'name' => 'Expiry RBAC certification',
            'is_required_for_dispatch' => false,
            'warning_days_before_expiry' => 30,
        ]);
        UserCertification::query()->create([
            'user_id' => $certificationOwner->id,
            'certification_id' => $certification->id,
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->addDays(10)->toDateString(),
            'certificate_number' => 'EXPIRY-RBAC-PRIVATE',
            'status' => 'active',
        ]);

        $assetViewer = $this->user('expiry-asset-viewer@example.test');
        $this->grant($assetViewer, ['expiry.view', 'assets.view']);
        $assetResponse = $this->asWebClient($assetViewer)
            ->getJson('/api/expiry-overview')
            ->assertOk()
            ->assertJsonPath('data.assets.0.asset_tag', 'EXPIRY-RBAC-ASSET')
            ->assertJsonPath('data.certifications', []);
        $this->assertStringNotContainsString('EXPIRY-RBAC-PRIVATE', $assetResponse->getContent());

        $certificationViewer = $this->user('expiry-certification-viewer@example.test');
        $this->grant($certificationViewer, ['expiry.view', 'certifications.view']);
        $certificationResponse = $this->asWebClient($certificationViewer)
            ->getJson('/api/expiry-overview')
            ->assertOk()
            ->assertJsonPath('data.assets', [])
            ->assertJsonPath('data.certifications.0.certificate_number', 'EXPIRY-RBAC-PRIVATE');
        $this->assertStringNotContainsString('EXPIRY-RBAC-ASSET', $certificationResponse->getContent());
    }

    public function test_navigation_permission_migration_preserves_equivalent_existing_role_access(): void
    {
        $legacyRole = Role::query()->create([
            'name' => 'legacy-navigation-role',
            'display_name' => 'Legacy navigation role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        foreach ([
            'assets.view',
            'settings.manage',
            'system.health.view',
            'system.routing.manage',
            'system.queues.manage',
            'users.view',
            'users.manage',
        ] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'category' => 'migration-test',
                    'display_name' => $permissionName,
                    'description' => 'Migration compatibility permission',
                ],
            );
            $legacyRole->permissions()->attach($permission->id, ['created_at' => now()]);
        }

        $legacyVacationViewer = Role::query()->create([
            'name' => 'legacy-vacation-viewer',
            'display_name' => 'Legacy vacation viewer',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $legacyVacationViewer->permissions()->attach(
            Permission::query()->where('name', 'users.view')->value('id'),
            ['created_at' => now()],
        );
        $legacyVacationManager = Role::query()->create([
            'name' => 'legacy-vacation-manager',
            'display_name' => 'Legacy vacation manager',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $legacyVacationManager->permissions()->attach(
            Permission::query()->where('name', 'users.manage')->value('id'),
            ['created_at' => now()],
        );

        $legacyRole->permissions()->detach(
            Permission::query()
                ->whereIn('name', [
                    'expiry.view',
                    'forms.manage',
                    'knmi.manage',
                    'branding.manage',
                    'system.routing.view',
                    'system.queues.view',
                    'vacations.view',
                    'vacations.manage',
                ])
                ->pluck('id'),
        );

        $migration = require database_path('migrations/2026_07_25_000001_add_web_navigation_permissions.php');
        $migration->up();

        $this->assertEqualsCanonicalizing([
            'branding.manage',
            'expiry.view',
            'forms.manage',
            'knmi.manage',
            'system.queues.view',
            'system.routing.view',
        ], $legacyRole->permissions()
            ->whereIn('permissions.name', [
                'expiry.view',
                'forms.manage',
                'knmi.manage',
                'branding.manage',
                'system.routing.view',
                'system.queues.view',
            ])
            ->pluck('permissions.name')
            ->all());
        $this->assertStringContainsString(
            'Formulieren, KNMI en branding hebben afzonderlijke rechten.',
            (string) Permission::query()->where('name', 'settings.manage')->value('description'),
        );
        $this->assertStringContainsString(
            'Wachtrijen en routering hebben afzonderlijke bekijkrechten.',
            (string) Permission::query()->where('name', 'system.health.view')->value('description'),
        );
        $this->assertEqualsCanonicalizing(
            ['vacations.manage', 'vacations.view'],
            $legacyRole->permissions()
                ->whereIn('permissions.name', ['vacations.view', 'vacations.manage'])
                ->pluck('permissions.name')
                ->all(),
        );
        $this->assertSame(
            ['vacations.view'],
            $legacyVacationViewer->permissions()
                ->whereIn('permissions.name', ['vacations.view', 'vacations.manage'])
                ->pluck('permissions.name')
                ->all(),
        );
        $this->assertSame(
            ['vacations.manage'],
            $legacyVacationManager->permissions()
                ->whereIn('permissions.name', ['vacations.view', 'vacations.manage'])
                ->pluck('permissions.name')
                ->all(),
        );
        $this->assertSame(
            [],
            $legacyRole->permissions()
                ->whereIn('permissions.name', ['status.view', 'status.override'])
                ->pluck('permissions.name')
                ->all(),
        );

        $migration->down();

        $this->assertDatabaseMissing('permissions', ['name' => 'vacations.view']);
        $this->assertDatabaseMissing('permissions', ['name' => 'vacations.manage']);
        $this->assertDatabaseHas('permissions', ['name' => 'status.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'status.override']);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Navigation Permission User',
            'first_name' => 'Navigation',
            'last_name' => 'Permission User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function grant(User $user, array $permissionNames): void
    {
        $role = Role::query()->create([
            'name' => 'navigation-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Navigation test role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'category' => 'navigation-test',
                    'display_name' => $permissionName,
                    'description' => 'Navigation test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }

        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken('Navigation permission client', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
