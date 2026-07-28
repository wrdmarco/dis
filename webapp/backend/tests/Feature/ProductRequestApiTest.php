<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\ProductRequest;
use App\Models\ProductRequestStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\WebSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ProductRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_ORIGIN = 'https://dis.example.test';

    public function test_permission_migration_is_idempotent_and_grants_only_the_intended_roles(): void
    {
        $administrator = $this->role('system-administrator', true);
        $webRole = $this->role('existing-web-role', true);
        $operatorRole = $this->role('existing-operator-role', false);
        $migration = require database_path('migrations/2026_07_27_000010_add_product_request_permissions_and_role.php');

        $migration->up();
        $migration->up();

        $handler = Role::query()->where('name', 'request-handler')->sole();
        $this->assertSame('Verzoekafhandelaar', $handler->display_name);
        $this->assertTrue($handler->can_use_admin_app);
        $this->assertFalse($handler->can_use_operator_app);
        $this->assertSame([
            'product-requests.create',
            'product-requests.resolve',
            'product-requests.update-own',
            'product-requests.view',
        ], $this->rolePermissionNames($handler));
        $this->assertSame([
            'product-requests.create',
            'product-requests.update-own',
            'product-requests.view',
        ], $this->rolePermissionNames($webRole->refresh()));
        $this->assertSame([
            'product-requests.create',
            'product-requests.resolve',
            'product-requests.update-own',
            'product-requests.view',
        ], $this->rolePermissionNames($administrator->refresh()));
        $this->assertSame([], $this->rolePermissionNames($operatorRole->refresh()));
        $this->assertSame(0, DB::table('role_user')->where('role_id', $handler->id)->count());
        $this->assertSame(4, DB::table('permission_role')->where('role_id', $handler->id)->count());
    }

    public function test_routes_require_authentication_and_the_specific_permissions(): void
    {
        $this->getJson('/api/product-requests')->assertUnauthorized();
        $this->postJson('/api/product-requests', [
            'type' => 'bug',
            'title' => 'Geen toegang',
            'description' => 'Deze melding mag niet worden opgeslagen.',
        ])->assertUnauthorized();

        $user = $this->user('Zonder Rechten', 'no-product-request-rights@example.test', []);

        $this->asWebClient($user)
            ->getJson('/api/product-requests')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->asWebClient($user)
            ->postJson('/api/product-requests', [
                'type' => 'feature',
                'title' => 'Niet toegestaan',
                'description' => 'De gebruiker mist het aanmaakrecht.',
            ])
            ->assertForbidden();

        $createOnly = $this->user(
            'Alleen Aanmaken',
            'product-request-create-only@example.test',
            ['product-requests.create'],
        );
        $this->asWebClient($createOnly)
            ->postJson('/api/product-requests', [
                'type' => 'feature',
                'title' => 'Leesrecht ontbreekt',
                'description' => 'Een actierecht zonder leesrecht mag de API niet openen.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('product_requests', 0);
    }

    public function test_bearer_tokens_cannot_use_the_stateful_web_only_product_request_surface(): void
    {
        $user = $this->user(
            'Duaal Account',
            'product-request-dual-client@example.test',
            [],
        );
        $role = Role::query()->create([
            'name' => 'product-request-dual-client-role',
            'display_name' => 'Product request dual client role',
            'description' => 'Product request dual client role',
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
        ]);
        $permissions = Permission::query()
            ->whereIn('name', [
                'product-requests.view',
                'product-requests.create',
                'product-requests.update-own',
                'product-requests.resolve',
            ])
            ->get();
        $role->permissions()->attach(
            $permissions->pluck('id')->all(),
            ['created_at' => now()],
        );
        $user->roles()->attach($role->id, ['created_at' => now()]);

        $id = (string) $this->asWebClient($user)
            ->postJson('/api/product-requests', [
                'type' => 'bug',
                'title' => 'Alleen via web',
                'description' => 'Operator-tokens mogen dit verzoek niet uitlezen of wijzigen.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asClient($user, 'client:operator')
            ->getJson('/api/product-requests')
            ->assertForbidden();
        $this->asBearerClient($user, ['*'])
            ->getJson('/api/product-requests')
            ->assertForbidden();
        $this->asClient($user, 'client:web')
            ->getJson('/api/product-requests')
            ->assertForbidden();
        $this->asClient($user, 'client:operator')
            ->postJson('/api/product-requests', [
                'type' => 'feature',
                'title' => 'Operator-poging',
                'description' => 'Dit verzoek mag niet worden aangemaakt.',
            ])
            ->assertForbidden();
        $this->asClient($user, 'client:operator')
            ->patchJson("/api/product-requests/{$id}", [
                'lock_version' => 1,
                'title' => 'Operator-poging',
            ])
            ->assertForbidden();
        $this->asClient($user, 'client:operator')
            ->patchJson("/api/product-requests/{$id}/status", [
                'lock_version' => 1,
                'status' => 'resolved',
                'resolution_note' => 'Operator-poging',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('product_requests', 1);
        $this->assertDatabaseHas('product_requests', [
            'id' => $id,
            'status' => 'open',
            'title' => 'Alleen via web',
        ]);
    }

    public function test_create_uses_server_owned_fields_snapshots_the_requester_and_audits_without_content(): void
    {
        $creator = $this->user(
            'Anja Aanvrager',
            'product-request-create@example.test',
            ['product-requests.view', 'product-requests.create', 'product-requests.update-own'],
        );

        $this->asWebClient($creator)
            ->postJson('/api/product-requests', [
                'type' => 'bug',
                'title' => '   ',
                'description' => " \n ",
            ])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'title',
                        'description',
                    ],
                ],
            ]);

        $this->asWebClient($creator)
            ->postJson('/api/product-requests', [
                'type' => 'bug',
                'title' => 'Kaart opent niet',
                'description' => 'De kaart blijft leeg na het openen van de pagina.',
                'status' => 'resolved',
                'requester_id' => (string) str()->ulid(),
                'lock_version' => 99,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'status',
                        'requester_id',
                        'lock_version',
                    ],
                ],
            ]);

        $response = $this->asWebClient($creator)
            ->postJson('/api/product-requests', [
                'type' => 'bug',
                'title' => 'Kaart opent niet',
                'description' => 'De kaart blijft leeg na het openen van de pagina.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'bug')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.requester.id', $creator->id)
            ->assertJsonPath('data.requester.name', 'Anja Aanvrager')
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.can_update', true)
            ->assertJsonPath('data.can_resolve', false)
            ->assertJsonPath('data.status_history.0.from_status', null)
            ->assertJsonPath('data.status_history.0.to_status', 'open')
            ->assertJsonPath('data.status_history.0.changed_by.name', 'Anja Aanvrager');

        $this->assertStringNotContainsString('email', $response->getContent());
        $productRequest = ProductRequest::query()->sole();
        $this->assertSame($creator->id, $productRequest->requester_id);
        $this->assertSame('Anja Aanvrager', $productRequest->requester_name_snapshot);
        $this->assertSame($creator->id, $productRequest->updated_by);
        $this->assertNull($productRequest->resolved_by);
        $this->assertNull($productRequest->resolved_at);
        $this->assertDatabaseCount('product_request_status_histories', 1);

        $audit = AuditLog::query()
            ->where('action', 'product_requests.created')
            ->where('target_id', $productRequest->id)
            ->sole();
        $auditJson = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Kaart opent niet', $auditJson);
        $this->assertStringNotContainsString('De kaart blijft leeg', $auditJson);
        $this->assertNull($audit->reason);
        $this->assertSame('bug', $audit->metadata['type']);
        $this->assertSame('open', $audit->metadata['status']);
    }

    public function test_viewers_see_all_requests_while_filters_search_pagination_and_mine_are_server_side(): void
    {
        $first = $this->user(
            'Iris Indiener',
            'product-request-first@example.test',
            ['product-requests.view', 'product-requests.create'],
        );
        $second = $this->user(
            'Bram Melder',
            'product-request-second@example.test',
            ['product-requests.view', 'product-requests.create'],
        );

        $this->asWebClient($first)->postJson('/api/product-requests', [
            'type' => 'feature',
            'title' => 'Donkere kaartlaag',
            'description' => 'Voeg een kaartlaag toe voor nachtelijke inzet.',
        ])->assertCreated();
        $this->asWebClient($second)->postJson('/api/product-requests', [
            'type' => 'bug',
            'title' => 'Procentteken % blijft staan',
            'description' => 'De agenda toont een verkeerde maand.',
        ])->assertCreated();

        $this->asWebClient($first)
            ->getJson('/api/product-requests?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);
        $this->asWebClient($first)
            ->getJson('/api/product-requests?mine=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requester.name', 'Iris Indiener');
        $this->asWebClient($first)
            ->getJson('/api/product-requests?type=bug&search=AGENDA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Procentteken % blijft staan');
        $this->asWebClient($first)
            ->getJson('/api/product-requests?search=%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Procentteken % blijft staan');
        $this->asWebClient($first)
            ->getJson('/api/product-requests?search=Iris%20Indiener')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requester.name', 'Iris Indiener');
        $this->asWebClient($first)
            ->getJson('/api/product-requests?search=%20%20%20')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['search']]]);
        $this->asWebClient($first)
            ->getJson('/api/product-requests?search='.str_repeat('x', 121))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['search']]]);

        $requests = ProductRequest::query()->orderBy('id')->get();
        $requests[0]->forceFill(['status' => 'resolved'])->save();
        $requests[1]->forceFill(['status' => 'rejected'])->save();

        $closed = $this->asWebClient($first)
            ->getJson('/api/product-requests?status=resolved,rejected')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing(
            ['resolved', 'rejected'],
            collect($closed->json('data'))->pluck('status')->all(),
        );
        $this->asWebClient($first)
            ->getJson('/api/product-requests?status=resolved,closed')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status']]]);
    }

    public function test_only_the_owner_can_update_non_terminal_content_and_stale_versions_conflict(): void
    {
        $owner = $this->user(
            'Olaf Eigenaar',
            'product-request-owner@example.test',
            ['product-requests.view', 'product-requests.create', 'product-requests.update-own'],
        );
        $other = $this->user(
            'Oona Andere',
            'product-request-other@example.test',
            ['product-requests.view', 'product-requests.update-own'],
        );
        $id = (string) $this->asWebClient($owner)
            ->postJson('/api/product-requests', [
                'type' => 'change',
                'title' => 'Eerste titel',
                'description' => 'De oorspronkelijke omschrijving.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asWebClient($other)
            ->patchJson("/api/product-requests/{$id}", [
                'lock_version' => 1,
                'title' => 'Onrechtmatige wijziging',
            ])
            ->assertForbidden();

        $this->asWebClient($owner)
            ->patchJson("/api/product-requests/{$id}", [
                'lock_version' => 1,
                'title' => 'Bijgewerkte titel',
                'description' => 'De eigenaar heeft extra informatie toegevoegd.',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Bijgewerkte titel')
            ->assertJsonPath('data.lock_version', 2);

        $this->asWebClient($owner)
            ->patchJson("/api/product-requests/{$id}", [
                'lock_version' => 1,
                'title' => 'Verouderde wijziging',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'product_request_version_conflict')
            ->assertJsonPath('error.details.current.id', $id)
            ->assertJsonPath('error.details.current.lock_version', 2);

        $this->asWebClient($owner)
            ->patchJson("/api/product-requests/{$id}", [
                'lock_version' => 2,
                'status' => 'resolved',
                'title' => 'Poging om status te wijzigen',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $productRequest = ProductRequest::query()->findOrFail($id);
        $this->assertSame('Bijgewerkte titel', $productRequest->title);
        $this->assertSame('open', $productRequest->status);
        $audit = AuditLog::query()
            ->where('action', 'product_requests.updated')
            ->where('target_id', $id)
            ->sole();
        $this->assertSame(['title', 'description'], $audit->metadata['changed_fields']);
        $this->assertStringNotContainsString(
            'extra informatie',
            json_encode($audit->metadata, JSON_THROW_ON_ERROR),
        );
    }

    public function test_handler_controls_transitions_with_required_notes_and_preserved_history(): void
    {
        $owner = $this->user(
            'Fleur Feature',
            'product-request-transition-owner@example.test',
            ['product-requests.view', 'product-requests.create', 'product-requests.update-own'],
        );
        $handler = $this->user(
            'Henk Handler',
            'product-request-handler@example.test',
            ['product-requests.view', 'product-requests.resolve'],
        );
        $id = (string) $this->asWebClient($owner)
            ->postJson('/api/product-requests', [
                'type' => 'feature',
                'title' => 'Nieuwe export',
                'description' => 'Voeg een compacte export van verzoeken toe.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asWebClient($owner)
            ->patchJson("/api/product-requests/{$id}/status", [
                'lock_version' => 1,
                'status' => 'in_progress',
            ])
            ->assertForbidden();

        $this->asWebClient($handler)
            ->patchJson("/api/product-requests/{$id}/status", [
                'lock_version' => 1,
                'status' => 'in_progress',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonCount(2, 'data.status_history');

        $this->asWebClient($handler)
            ->patchJson("/api/product-requests/{$id}/status", [
                'lock_version' => 2,
                'status' => 'resolved',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['resolution_note']]]);

        $this->asWebClient($handler)
            ->patchJson("/api/product-requests/{$id}/status", [
                'lock_version' => 2,
                'status' => 'resolved',
                'resolution_note' => 'Opgelost in release met interne referentie GEHEIM-123.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution_note', 'Opgelost in release met interne referentie GEHEIM-123.')
            ->assertJsonPath('data.resolved_by.id', $handler->id)
            ->assertJsonPath('data.resolved_by.name', 'Henk Handler')
            ->assertJsonPath('data.lock_version', 3)
            ->assertJsonPath('data.can_update', false)
            ->assertJsonCount(3, 'data.status_history');

        $this->asWebClient($owner)
            ->patchJson("/api/product-requests/{$id}", [
                'lock_version' => 3,
                'title' => 'Niet meer aanpasbaar',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'product_request_terminal');

        $this->asWebClient($handler)
            ->patchJson("/api/product-requests/{$id}/status", [
                'lock_version' => 3,
                'status' => 'in_progress',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'product_request_transition_conflict');
        $this->asWebClient($handler)
            ->patchJson("/api/product-requests/{$id}/status", [
                'lock_version' => 3,
                'status' => 'open',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['resolution_note']]]);

        $this->asWebClient($handler)
            ->patchJson("/api/product-requests/{$id}/status", [
                'lock_version' => 3,
                'status' => 'open',
                'resolution_note' => 'Heropend omdat de export nog niet compleet is.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.resolution_note', null)
            ->assertJsonPath('data.resolved_by', null)
            ->assertJsonPath('data.resolved_at', null)
            ->assertJsonPath('data.lock_version', 4)
            ->assertJsonPath('data.status_history.2.note', 'Opgelost in release met interne referentie GEHEIM-123.')
            ->assertJsonPath('data.status_history.3.note', 'Heropend omdat de export nog niet compleet is.')
            ->assertJsonCount(4, 'data.status_history');

        $request = ProductRequest::query()->findOrFail($id);
        $this->assertNull($request->resolution_note);
        $this->assertNull($request->resolved_by);
        $this->assertNull($request->resolved_at);
        $this->assertSame(4, ProductRequestStatusHistory::query()->where('product_request_id', $id)->count());

        $resolvedAudit = AuditLog::query()
            ->where('action', 'product_requests.resolved')
            ->where('target_id', $id)
            ->sole();
        $reopenedAudit = AuditLog::query()
            ->where('action', 'product_requests.reopened')
            ->where('target_id', $id)
            ->sole();
        foreach ([$resolvedAudit, $reopenedAudit] as $audit) {
            $metadata = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('GEHEIM-123', $metadata);
            $this->assertStringNotContainsString('niet compleet', $metadata);
            $this->assertNull($audit->reason);
        }
    }

    public function test_snapshots_survive_user_removal_and_no_delete_surface_exists(): void
    {
        $owner = $this->user(
            'Historische Indiener',
            'product-request-deleted-user@example.test',
            ['product-requests.view', 'product-requests.create'],
        );
        $viewer = $this->user(
            'Latere Lezer',
            'product-request-later-viewer@example.test',
            ['product-requests.view'],
        );
        $id = (string) $this->asWebClient($owner)
            ->postJson('/api/product-requests', [
                'type' => 'change',
                'title' => 'Historisch verzoek',
                'description' => 'Dit verzoek blijft bestaan nadat de indiener wordt verwijderd.',
            ])
            ->assertCreated()
            ->json('data.id');

        $owner->forceDelete();

        $this->asWebClient($viewer)
            ->getJson("/api/product-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.requester.id', null)
            ->assertJsonPath('data.requester.name', 'Historische Indiener')
            ->assertJsonPath('data.status_history.0.changed_by.id', null)
            ->assertJsonPath('data.status_history.0.changed_by.name', 'Historische Indiener');

        $this->asWebClient($viewer)
            ->deleteJson("/api/product-requests/{$id}")
            ->assertMethodNotAllowed();
        $this->assertDatabaseHas('product_requests', ['id' => $id]);
    }

    public function test_required_audit_failure_rolls_back_request_and_history(): void
    {
        $creator = $this->user(
            'Audit Fout',
            'product-request-audit-failure@example.test',
            ['product-requests.view', 'product-requests.create'],
        );

        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'product_requests.created') {
                throw new \RuntimeException('Simulated product-request audit failure.');
            }
        });

        try {
            $this->asWebClient($creator)
                ->postJson('/api/product-requests', [
                    'type' => 'bug',
                    'title' => 'Audit moet slagen',
                    'description' => 'De transactie mag zonder auditregel niet worden vastgelegd.',
                ])
                ->assertInternalServerError();
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertDatabaseCount('product_requests', 0);
        $this->assertDatabaseCount('product_request_status_histories', 0);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function user(string $name, string $email, array $permissionNames): User
    {
        $user = User::query()->create([
            'name' => $name,
            'first_name' => str($name)->before(' ')->toString(),
            'last_name' => str($name)->after(' ')->toString(),
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = $this->role('product-request-test-'.str()->lower((string) str()->ulid()), true);

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->where('name', $permissionName)->firstOrFail();
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function role(string $name, bool $webAccess): Role
    {
        return Role::query()->create([
            'name' => $name,
            'display_name' => $name,
            'description' => $name,
            'can_use_operator_app' => ! $webAccess,
            'can_use_admin_app' => $webAccess,
        ]);
    }

    /** @return list<string> */
    private function rolePermissionNames(Role $role): array
    {
        return $role->permissions()
            ->where('permissions.name', 'like', 'product-requests.%')
            ->pluck('permissions.name')
            ->sort()
            ->values()
            ->all();
    }

    private function asWebClient(User $user): static
    {
        config([
            'app.url' => self::WEB_ORIGIN,
            'session.trusted_origins' => [self::WEB_ORIGIN],
            'sanctum.stateful' => ['dis.example.test'],
        ]);

        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        $timestamp = now()->getTimestamp();
        $csrfToken = hash('sha256', 'product-request-browser-session-'.$user->id);

        return $this->actingAs($user, 'web')
            ->withSession([
                '_token' => $csrfToken,
                WebSessionService::KEY_AUTHENTICATED_AT => $timestamp,
                WebSessionService::KEY_LAST_ACTIVITY_AT => $timestamp,
                WebSessionService::KEY_AUTH_VERSION => (int) $user->auth_session_version,
            ])
            ->withHeaders([
                'Accept' => 'application/json',
                'Origin' => self::WEB_ORIGIN,
                'Referer' => self::WEB_ORIGIN.'/',
                'Sec-Fetch-Site' => 'same-origin',
                'X-CSRF-TOKEN' => $csrfToken,
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->withServerVariables([
                'HTTP_HOST' => 'dis.example.test',
                'HTTPS' => 'on',
            ]);
    }

    private function asClient(User $user, string $ability): static
    {
        return $this->asBearerClient($user, ['*', $ability]);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function asBearerClient(User $user, array $abilities): static
    {
        $token = $user->createToken(
            'Product request feature test',
            $abilities,
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
