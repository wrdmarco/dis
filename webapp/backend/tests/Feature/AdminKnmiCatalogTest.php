<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AdminKnmiCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_URL = 'https://dataplatform.knmi.nl/api/3/action/package_search';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_catalog_requires_settings_permission_and_validates_bounded_filters(): void
    {
        Http::preventStrayRequests();
        $this->getJson('/api/admin/knmi/catalog')->assertUnauthorized();

        $viewer = $this->user('knmi-catalog-viewer@example.test', []);
        $this->asAdminClient($viewer)
            ->getJson('/api/admin/knmi/catalog')
            ->assertForbidden();

        $manager = $this->user('knmi-catalog-manager@example.test', ['settings.manage']);
        $this->asAdminClient($manager)
            ->getJson('/api/admin/knmi/catalog?status=deleted')
            ->assertUnprocessable();
        $this->asAdminClient($manager)
            ->getJson('/api/admin/knmi/catalog?license=CC-BY-4.0%22%20OR%20*:*')
            ->assertUnprocessable();
        $this->asAdminClient($manager)
            ->getJson('/api/admin/knmi/catalog?per_page=51')
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_catalog_is_compact_searchable_paginated_cached_and_uses_the_fixed_knmi_host(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::SEARCH_URL.'*' => Http::response($this->catalogPayload(), 200),
        ]);
        $manager = $this->user('knmi-catalog-search@example.test', ['settings.manage']);
        $url = '/api/admin/knmi/catalog?query=radar&page=2&per_page=1&status=ongoing&license=CC-BY-4.0';

        $response = $this->asAdminClient($manager)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.items.0.key', 'radar-forecast-2-0')
            ->assertJsonPath('data.items.0.dataset', 'radar_forecast')
            ->assertJsonPath('data.items.0.version', '2.0')
            ->assertJsonPath('data.items.0.status', 'ongoing')
            ->assertJsonPath('data.items.0.license_id', 'CC-BY-4.0')
            ->assertJsonPath('data.items.0.is_open', true)
            ->assertJsonPath('data.items.0.formats.0', 'HDF5')
            ->assertJsonPath('data.items.0.topics.0', 'Precipitation')
            ->assertJsonPath('data.items.0.metadata_updated_at', '2026-07-20T08:57:42+00:00')
            ->assertJsonPath('data.items.0.source_url', 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0')
            ->assertJsonPath('data.pagination.page', 2)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 408)
            ->assertJsonPath('data.pagination.last_page', 408)
            ->assertJsonPath('data.pagination.from', 2)
            ->assertJsonPath('data.pagination.to', 2)
            ->assertJsonPath('data.filters.statuses.0.value', 'ongoing')
            ->assertJsonPath('data.filters.licenses.0.value', 'CC-BY-4.0')
            ->assertJsonPath('data.catalog.available', true)
            ->assertJsonPath('data.catalog.cache_state', 'fresh')
            ->assertJsonPath('data.catalog.warning', null);

        $this->assertStringContainsString('Radar forecast with observed precipitation.', (string) $response->json('data.items.0.description'));
        $this->assertStringNotContainsString('<strong>', (string) $response->json('data.items.0.description'));
        $this->assertStringNotContainsString('Technical documentation contents', $response->getContent());
        $this->assertSame([
            'key',
            'title',
            'dataset',
            'version',
            'description',
            'status',
            'license_id',
            'license_title',
            'is_open',
            'formats',
            'topics',
            'publication_at',
            'metadata_updated_at',
            'source_url',
        ], array_keys($response->json('data.items.0')));

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return parse_url($request->url(), PHP_URL_SCHEME) === 'https'
                && parse_url($request->url(), PHP_URL_HOST) === 'dataplatform.knmi.nl'
                && parse_url($request->url(), PHP_URL_PATH) === '/api/3/action/package_search'
                && ($query['q'] ?? null) === 'radar'
                && ($query['rows'] ?? null) === '1'
                && ($query['start'] ?? null) === '1'
                && ($query['fq'] ?? null) === 'organization:knmi AND extras_Status:onGoing AND license_id:"CC-BY-4.0"';
        });

        $this->asAdminClient($manager)->getJson($url)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_catalog_falls_back_to_stale_cache_and_never_breaks_admin_status_on_provider_failure(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence(self::SEARCH_URL.'*')
            ->push($this->catalogPayload(), 200)
            ->pushStatus(503)
            ->pushStatus(503);
        $manager = $this->user('knmi-catalog-fallback@example.test', ['settings.manage']);

        CarbonImmutable::setTestNow('2026-07-23T12:00:00Z');
        try {
            $this->asAdminClient($manager)
                ->getJson('/api/admin/knmi/catalog?query=radar')
                ->assertOk()
                ->assertJsonPath('data.catalog.cache_state', 'fresh');

            CarbonImmutable::setTestNow('2026-07-23T12:16:00Z');
            $this->asAdminClient($manager)
                ->getJson('/api/admin/knmi/catalog?query=radar')
                ->assertOk()
                ->assertJsonPath('data.items.0.key', 'radar-forecast-2-0')
                ->assertJsonPath('data.catalog.available', true)
                ->assertJsonPath('data.catalog.cache_state', 'stale')
                ->assertJsonPath('data.catalog.fetched_at', '2026-07-23T12:00:00+00:00');

            Cache::flush();
            $this->asAdminClient($manager)
                ->getJson('/api/admin/knmi/catalog?query=bliksem')
                ->assertOk()
                ->assertJsonCount(0, 'data.items')
                ->assertJsonPath('data.catalog.available', false)
                ->assertJsonPath('data.catalog.cache_state', 'unavailable')
                ->assertJsonPath('data.catalog.fetched_at', null);

            $this->asAdminClient($manager)
                ->getJson('/api/admin/knmi')
                ->assertOk()
                ->assertJsonCount(7, 'data.datasets');
        } finally {
            CarbonImmutable::setTestNow();
        }

        Http::assertSentCount(3);
    }

    /** @return array<string, mixed> */
    private function catalogPayload(): array
    {
        return [
            'success' => true,
            'result' => [
                'count' => 408,
                'results' => [[
                    'name' => 'radar-forecast-2-0',
                    'title' => 'Precipitation radar forecast for the Netherlands',
                    'version' => '2.0',
                    'notes' => '<strong>Radar forecast</strong> with observed precipitation.'.str_repeat(' details', 100),
                    'isopen' => true,
                    'license_id' => 'CC-BY-4.0',
                    'license_title' => 'Creative Commons Attribution 4.0',
                    'metadata_modified' => '2026-07-20T08:57:42.365963',
                    'extras' => [
                        ['key' => 'Dataset name', 'value' => 'radar_forecast'],
                        ['key' => 'Dataset version', 'value' => '2.0'],
                        ['key' => 'Status', 'value' => 'onGoing'],
                        ['key' => 'Publication timestamp', 'value' => '2024-01-19'],
                        ['key' => 'File formats', 'value' => '["HDF5"]'],
                        ['key' => 'Technical documentation contents', 'value' => str_repeat('large', 1000)],
                    ],
                    'groups' => [[
                        'name' => 'precipitation',
                        'display_name' => 'Precipitation',
                    ]],
                    'resources' => [[
                        'format' => 'HDF5',
                        'url' => 'https://browser.dataplatform.knmi.nl/metadata/example',
                    ]],
                ]],
                'search_facets' => [
                    'license_id' => [
                        'items' => [[
                            'name' => 'CC-BY-4.0',
                            'display_name' => 'Creative Commons Attribution 4.0',
                            'count' => 360,
                        ]],
                    ],
                ],
            ],
        ];
    }

    /** @param list<string> $permissionNames */
    private function user(string $email, array $permissionNames): User
    {
        $user = User::query()->create([
            'name' => 'KNMI Manager',
            'first_name' => 'KNMI',
            'last_name' => 'Manager',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'knmi-catalog-'.str()->lower((string) str()->ulid()),
            'display_name' => 'KNMI catalog test role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        foreach ($permissionNames as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'category' => 'system_configuration', 'description' => $name],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('KNMI catalog test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
