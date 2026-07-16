<?php

namespace Tests\Feature;

use App\Contracts\RoutingProvider;
use App\DTO\Routing\RouteEstimate;
use App\DTO\Routing\RoutePoint;
use App\DTO\Routing\RouteSource;
use App\Events\OsrmOperationStatusChanged;
use App\Models\AuditLog;
use App\Models\OsrmOperation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\OsrmOperationService;
use App\Services\Routing\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class OsrmAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private string $stateRoot;

    private string $globalStatusPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateRoot = storage_path('framework/testing/osrm-admin-'.str()->lower((string) str()->ulid()));
        $this->globalStatusPath = $this->stateRoot.'/osrm-status.json';
        File::makeDirectory($this->stateRoot.'/requests', 0700, true);
        File::makeDirectory($this->stateRoot.'/results', 0700, true);
        config()->set([
            'dis.routing.admin_state_root' => $this->stateRoot,
            'dis.routing.admin_status_path' => $this->globalStatusPath,
            'dis.routing.admin_sources' => $this->configuredSources(),
            'dis.routing.admin_health_coordinate' => [
                'longitude' => 5.1214,
                'latitude' => 52.0907,
            ],
            'dis.routing.enabled' => false,
        ]);
        Event::fake([OsrmOperationStatusChanged::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stateRoot);

        parent::tearDown();
    }

    public function test_status_is_readable_by_health_or_routing_permission_but_start_requires_routing_manage(): void
    {
        $this->getJson('/api/admin/routing/osrm')->assertUnauthorized();

        $healthViewer = $this->user('osrm-health@example.test', ['system.health.view']);
        $this->asAdminClient($healthViewer)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk()
            ->assertJsonPath('data.state', 'not_installed')
            ->assertJsonPath('data.configuration.sources.0.id', 'netherlands')
            ->assertJsonPath('data.configuration.sources.0.label', 'Nederland')
            ->assertJsonPath('data.configuration.sources.1.id', 'belgium')
            ->assertJsonPath('data.configuration.sources.1.label', 'België')
            ->assertJsonPath('data.configuration.source_set_sha256', $this->sourceSetSha256())
            ->assertJsonPath('data.configuration.health_coordinate.longitude', 5.1214)
            ->assertJsonPath('data.configuration.health_coordinate.latitude', 52.0907)
            ->assertJsonPath('data.next_action', 'install_activate')
            ->assertJsonPath('data.active_operation', null)
            ->assertJsonPath('data.latest_operation', null);

        $this->asAdminClient($healthViewer)
            ->postJson('/api/admin/routing/osrm/operations', $this->installPayload())
            ->assertForbidden();

        $routingManager = $this->user('osrm-manager@example.test', ['system.routing.manage']);
        $this->asAdminClient($routingManager)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk();
    }

    public function test_non_approved_server_source_is_blocked_before_request_publication(): void
    {
        $sources = $this->configuredSources();
        $sources[1]['latest_url'] = 'https://download.geofabrik.de/europe/germany-latest.osm.pbf';
        config()->set('dis.routing.admin_sources', $sources);
        $actor = $this->user('osrm-source-policy@example.test', ['system.routing.manage']);

        $this->asAdminClient($actor)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk()
            ->assertJsonPath('data.configuration.sources', [])
            ->assertJsonPath('data.configuration.source_set_sha256', null)
            ->assertJsonPath('data.next_action', null)
            ->assertJsonPath('data.blocker.code', 'invalid_source_configuration');

        $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', $this->installPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'osrm_operation_conflict');

        $this->assertDatabaseCount('osrm_operations', 0);
        $this->assertSame([], glob($this->stateRoot.'/requests/*.pending') ?: []);
    }

    public function test_invalid_server_probe_is_blocked_before_request_publication(): void
    {
        config()->set('dis.routing.admin_health_coordinate', [
            'longitude' => 13.4050,
            'latitude' => 52.5200,
        ]);
        $actor = $this->user('osrm-probe-policy@example.test', ['system.routing.manage']);

        $this->asAdminClient($actor)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk()
            ->assertJsonPath('data.configuration.health_coordinate', null)
            ->assertJsonPath('data.next_action', null)
            ->assertJsonPath('data.blocker.code', 'invalid_health_coordinate_configuration');

        $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', $this->installPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'osrm_operation_conflict');

        $this->assertDatabaseCount('osrm_operations', 0);
        $this->assertSame([], glob($this->stateRoot.'/requests/*.pending') ?: []);
    }

    public function test_initial_operation_publishes_only_the_minimal_root_broker_contract_with_mode_0600(): void
    {
        $actor = $this->user('osrm-publisher@example.test', ['system.routing.manage']);

        $response = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', [
                'action' => 'install_activate',
                'health_coordinate' => [
                    'longitude' => 4.895168,
                    'latitude' => 52.370216,
                ],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.operation.action', 'install_activate')
            ->assertJsonPath('data.operation.state', 'queued')
            ->assertJsonPath('data.operation.stage', 'validating');

        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));
        $this->assertSame(5.1214, (float) $operation->health_longitude);
        $this->assertSame(52.0907, (float) $operation->health_latitude);
        $pendingFiles = glob($this->stateRoot.'/requests/*.pending') ?: [];
        $this->assertCount(1, $pendingFiles);
        $payload = json_decode((string) file_get_contents($pendingFiles[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['version', 'operation_id', 'action', 'actor_id', 'created_at'],
            array_keys($payload),
        );
        $this->assertSame(2, $payload['version']);
        $this->assertSame($operation->id, $payload['operation_id']);
        $this->assertSame($actor->id, $payload['actor_id']);
        $this->assertArrayNotHasKey('source_url', $payload);
        $this->assertArrayNotHasKey('source_md5', $payload);
        $this->assertArrayNotHasKey('source_sha256', $payload);
        $this->assertArrayNotHasKey('health_coordinate', $payload);
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($pendingFiles[0]) & 0777);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'routing.osrm.operation_requested',
            'target_id' => $operation->id,
        ]);
        Event::assertDispatched(OsrmOperationStatusChanged::class);

        $status = $this->asAdminClient($actor)->getJson('/api/admin/routing/osrm')->assertOk();
        $status->assertJsonPath('data.next_action', null);
        $status->assertJsonPath('data.active_operation.id', $operation->id);
        $status->assertJsonPath('data.latest_operation.id', $operation->id);

        $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', $this->installPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'osrm_operation_conflict');
    }

    public function test_audit_failure_rolls_back_the_operation_before_any_root_request_is_published(): void
    {
        $actor = $this->user('osrm-audit-failure@example.test', ['system.routing.manage']);
        AuditLog::creating(function (AuditLog $auditLog): void {
            if ($auditLog->action === 'routing.osrm.operation_requested') {
                throw new \RuntimeException('Simulated required audit failure.');
            }
        });

        try {
            app(OsrmOperationService::class)->start(
                action: 'install_activate',
                actor: $actor,
            );
            $this->fail('The required audit failure must abort the OSRM operation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated required audit failure.', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertDatabaseCount('osrm_operations', 0);
        $this->assertSame([], glob($this->stateRoot.'/requests/*.pending') ?: []);
    }

    public function test_client_cannot_choose_sources_checksums_or_probe_and_update_uses_server_source_set(): void
    {
        $manifest = $this->sourceManifest();
        $this->writeReadyRuntimeStatus($manifest, healthCoordinate: '4.895168,52.370216');
        $this->putSetting('routing.enabled', true);
        $this->putSetting('routing.osrm.source_manifest', $manifest);
        $this->putSetting('routing.osrm.health_coordinate', [
            'longitude' => 4.895168,
            'latitude' => 52.370216,
        ]);
        $actor = $this->user('osrm-updater@example.test', ['system.routing.manage']);

        $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', [
                'action' => 'update',
                'source_md5' => str_repeat('b', 32),
                'source_url' => 'https://attacker.example.test/private.osm.pbf',
                'sources' => [[
                    'id' => 'attacker',
                    'latest_url' => 'https://attacker.example.test/private.osm.pbf',
                ]],
                'source_set' => [],
                'source_manifest' => [],
                'source_set_sha256' => str_repeat('b', 64),
                'snapshot_date' => '2026-07-15',
                'source_timestamp' => '2026-07-15T20:21:10Z',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', [
                'action' => 'update',
                'source_sha256' => str_repeat('b', 64),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $response = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', [
                'action' => 'update',
                'health_coordinate' => ['longitude' => 4.9, 'latitude' => 52.3],
            ])
            ->assertStatus(202);
        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));
        $this->assertNull($operation->source_url);
        $this->assertNull($operation->source_sha256);
        $this->assertSame($this->sourceSet(), $operation->source_set);
        $this->assertSame($this->sourceSetSha256(), $operation->source_set_sha256);
        $this->assertNull($operation->source_manifest);
        $this->assertSame(5.1214, (float) $operation->health_longitude);
        $this->assertSame(52.0907, (float) $operation->health_latitude);
    }

    public function test_next_action_requires_matching_managed_metadata_but_allows_a_degraded_managed_rebuild(): void
    {
        $manifest = $this->sourceManifest();
        $this->writeReadyRuntimeStatus($manifest);
        $this->putSetting('routing.enabled', true);
        $this->putSetting('routing.osrm.source_manifest', $manifest);
        $actor = $this->user('osrm-managed-state@example.test', ['system.routing.manage']);

        $this->asAdminClient($actor)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk()
            ->assertJsonPath('data.next_action', 'install_activate');

        $this->putSetting('routing.osrm.health_coordinate', [
            'longitude' => 5.1214,
            'latitude' => 52.0907,
        ]);
        $this->asAdminClient($actor)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk()
            ->assertJsonPath('data.next_action', 'update');

        $this->writeReadyRuntimeStatus($manifest, healthCoordinate: '4.895168,52.370216');
        $this->asAdminClient($actor)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk()
            ->assertJsonPath('data.next_action', 'install_activate');

        $this->writeReadyRuntimeStatus($manifest, healthy: false);
        $this->asAdminClient($actor)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk()
            ->assertJsonPath('data.state', 'degraded')
            ->assertJsonPath('data.next_action', 'update');

        $response = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', [
                'action' => 'update',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.operation.action', 'update')
            ->assertJsonPath('data.operation.state', 'queued');
        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));
        $this->assertSame($this->sourceSet(), $operation->source_set);
        $this->assertSame($this->sourceSetSha256(), $operation->source_set_sha256);
    }

    public function test_legacy_sha256_dataset_remains_available_until_a_composite_update_migrates_it(): void
    {
        $legacySha256 = str_repeat('9', 64);
        $this->writeReadyLegacyRuntimeStatus($legacySha256);
        $this->putSetting('routing.enabled', true);
        $this->putSetting('routing.osrm.source_url', 'https://download.geofabrik.de/europe/netherlands-latest.osm.pbf');
        $this->putSetting('routing.osrm.source_sha256', $legacySha256);
        $this->putSetting('routing.osrm.health_coordinate', [
            'longitude' => 5.1214,
            'latitude' => 52.0907,
        ]);
        $actor = $this->user('osrm-legacy-upgrade@example.test', ['system.routing.manage']);

        $this->asAdminClient($actor)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.dataset.legacy', true)
            ->assertJsonPath('data.dataset.source_set_sha256', null)
            ->assertJsonPath('data.dataset.sources', [])
            ->assertJsonMissingPath('data.dataset.legacy_sha256')
            ->assertJsonPath('data.next_action', 'update');

        $response = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', ['action' => 'update'])
            ->assertStatus(202);
        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));
        $this->assertNull($operation->source_sha256);
        $this->assertSame($this->sourceSet(), $operation->source_set);
    }

    public function test_live_feed_is_cursor_based_bounded_and_redacts_sensitive_output(): void
    {
        $manager = $this->user('osrm-log-manager@example.test', ['system.routing.manage']);
        $response = $this->asAdminClient($manager)
            ->postJson('/api/admin/routing/osrm/operations', $this->installPayload())
            ->assertStatus(202);
        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));
        $this->writeOperationStatus($operation, [
            'state' => 'running',
            'stage' => 'merging',
            'message' => 'Kaartdekking samenvoegen.',
            'progress_percent' => 10,
            'exit_code' => null,
            'active_source_manifest' => null,
        ]);
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->writeProtectedFile($this->stateRoot.'/results/'.$operation->id.'.log.jsonl', implode("\n", [
            json_encode(['version' => 2, 'seq' => 1, 'timestamp' => $now, 'stage' => 'validating', 'level' => 'info', 'message' => 'Validatie geslaagd.'], JSON_THROW_ON_ERROR),
            json_encode(['version' => 2, 'seq' => 2, 'timestamp' => $now, 'stage' => 'merging', 'level' => 'warning', 'message' => 'DB_PASSWORD=log-secret pad /opt/dis/private'], JSON_THROW_ON_ERROR),
            '{malformed',
            json_encode(['version' => 2, 'seq' => 3, 'timestamp' => $now, 'stage' => 'invalid-stage', 'level' => 'info', 'message' => 'hidden'], JSON_THROW_ON_ERROR),
        ])."\n");

        $healthViewer = $this->user('osrm-log-viewer@example.test', ['system.health.view']);
        $feed = $this->asAdminClient($healthViewer)
            ->getJson('/api/admin/routing/osrm/operations/'.$operation->id.'?after=1&limit=1')
            ->assertOk()
            ->assertJsonPath('data.operation.state', 'running')
            ->assertJsonPath('data.operation.stage', 'merging')
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.seq', 2)
            ->assertJsonPath('data.next_cursor', 2);
        $serialized = $feed->getContent();
        $this->assertStringContainsString('[REDACTED]', $serialized);
        $this->assertStringContainsString('[PATH]', $serialized);
        $this->assertStringNotContainsString('log-secret', $serialized);
        $this->assertStringNotContainsString('/opt/dis/private', $serialized);
    }

    public function test_root_callbacks_finish_only_after_matching_health_snapshot_and_enable_routing_from_database(): void
    {
        $manifest = $this->sourceManifest(str_repeat('c', 32), str_repeat('d', 32));
        $actor = $this->user('osrm-finisher@example.test', ['system.routing.manage']);
        $response = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', [
                ...$this->installPayload(),
            ])
            ->assertStatus(202);
        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));

        $this->assertSame(0, Artisan::call('dis:osrm-operation:mark-running', ['operationId' => $operation->id]));
        $this->putSetting('routing.osrm.source_url', 'https://download.geofabrik.de/europe/netherlands-latest.osm.pbf');
        $this->putSetting('routing.osrm.source_sha256', str_repeat('9', 64));
        $this->writeReadyRuntimeStatus($manifest);
        $this->writeOperationStatus($operation, [
            'state' => 'succeeded',
            'stage' => 'completed',
            'message' => 'OSRM gereed.',
            'progress_percent' => 100,
            'exit_code' => 0,
            'active_source_manifest' => $manifest,
        ]);
        $this->assertSame(0, Artisan::call('dis:osrm-operation:finish', [
            'operationId' => $operation->id,
            'exitCode' => 0,
        ]));

        $operation->refresh();
        $this->assertSame('succeeded', $operation->state);
        $this->assertNull($operation->active_key);
        $this->assertTrue(SystemSetting::boolean('routing.enabled', false));
        $this->assertSame($manifest, $operation->source_manifest);
        $this->assertEquals($manifest, SystemSetting::value('routing.osrm.source_manifest'));
        $this->assertNull(SystemSetting::value('routing.osrm.source_url'));
        $this->assertNull(SystemSetting::value('routing.osrm.source_sha256'));
        $this->assertEquals([
            'longitude' => 5.1214,
            'latitude' => 52.0907,
        ], SystemSetting::value('routing.osrm.health_coordinate'));
        $this->assertTrue(AuditLog::query()->where('action', 'routing.osrm.operation_started')->where('target_id', $operation->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'routing.osrm.operation_succeeded')->where('target_id', $operation->id)->exists());

        $status = $this->asAdminClient($actor)->getJson('/api/admin/routing/osrm')->assertOk();
        $status->assertJsonPath('data.state', 'ready');
        $status->assertJsonPath('data.dataset.legacy', false);
        $status->assertJsonPath('data.dataset.snapshot_date', '2026-07-15');
        $status->assertJsonPath('data.dataset.sources.0.id', 'netherlands');
        $status->assertJsonPath('data.dataset.sources.0.md5', str_repeat('c', 32));
        $status->assertJsonPath('data.dataset.sources.1.id', 'belgium');
        $status->assertJsonPath('data.dataset.sources.1.md5', str_repeat('d', 32));
        $status->assertJsonPath('data.next_action', 'update');
        $status->assertJsonPath('data.active_operation', null);
        $status->assertJsonPath('data.latest_operation.id', $operation->id);
        $status->assertJsonPath('data.latest_operation.state', 'succeeded');

        config()->set(['dis.routing.enabled' => false, 'dis.routing.provider' => 'osrm']);
        app()->instance(RoutingProvider::class, new class implements RoutingProvider
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function cacheNamespace(): string
            {
                return 'osrm-admin-binding-test';
            }

            public function routesTo(array $origins, RoutePoint $destination): array
            {
                return collect($origins)
                    ->mapWithKeys(fn (RoutePoint $origin, string $key): array => [$key => RouteEstimate::navigation(60, 1_000)])
                    ->all();
            }
        });
        app()->forgetInstance(RoutingService::class);
        $route = app(RoutingService::class)->routesTo(
            ['pilot' => new RoutePoint(52.0907, 5.1214)],
            new RoutePoint(52.1561, 5.3878),
        )['pilot'];
        $this->assertSame(RouteSource::Navigation, $route->source);
    }

    public function test_zero_exit_without_matching_root_snapshots_fails_closed_and_does_not_enable_routing(): void
    {
        $actor = $this->user('osrm-fail-closed@example.test', ['system.routing.manage']);
        $response = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', $this->installPayload())
            ->assertStatus(202);
        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));

        $this->assertSame(0, Artisan::call('dis:osrm-operation:finish', [
            'operationId' => $operation->id,
            'exitCode' => 0,
        ]));

        $this->assertSame('failed', $operation->refresh()->state);
        $this->assertFalse(SystemSetting::boolean('routing.enabled', false));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'routing.osrm.operation_failed',
            'target_id' => $operation->id,
        ]);
    }

    public function test_partial_composite_runtime_manifest_is_rejected_without_exposing_a_partial_md5(): void
    {
        $manifest = $this->sourceManifest();
        array_pop($manifest['sources']);
        $this->writeReadyRuntimeStatus($manifest);
        $actor = $this->user('osrm-partial-manifest@example.test', ['system.health.view']);

        $response = $this->asAdminClient($actor)
            ->getJson('/api/admin/routing/osrm')
            ->assertOk()
            ->assertJsonPath('data.state', 'installed_inactive')
            ->assertJsonPath('data.dataset', null)
            ->assertJsonPath('data.next_action', 'install_activate');

        $this->assertStringNotContainsString(str_repeat('a', 32), $response->getContent());
    }

    public function test_delayed_zero_exit_with_one_mismatched_supplier_md5_fails_closed(): void
    {
        $operationManifest = $this->sourceManifest(str_repeat('f', 32), str_repeat('d', 32));
        $runtimeManifest = $this->sourceManifest(str_repeat('f', 32), str_repeat('e', 32));
        $actor = $this->user('osrm-delayed-finish@example.test', ['system.routing.manage']);
        $response = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', [
                ...$this->installPayload(),
            ])
            ->assertStatus(202);
        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));

        $this->assertSame(0, Artisan::call('dis:osrm-operation:mark-running', ['operationId' => $operation->id]));
        $this->writeOperationStatus($operation, [
            'state' => 'succeeded',
            'stage' => 'completed',
            'message' => 'OSRM is volgens de rootbewerking gereed.',
            'progress_percent' => 100,
            'exit_code' => 0,
            'active_source_manifest' => $operationManifest,
        ]);
        $this->writeReadyRuntimeStatus($runtimeManifest);

        $this->assertSame(0, Artisan::call('dis:osrm-operation:finish', [
            'operationId' => $operation->id,
            'exitCode' => 0,
        ]));

        $operation->refresh();
        $this->assertSame('failed', $operation->state);
        $this->assertSame(1, $operation->exit_code);
        $this->assertSame(
            'OSRM-bewerking mislukt omdat de actieve routering niet veilig kon worden bevestigd.',
            $operation->message,
        );
        $this->assertStringNotContainsString('gereed', $operation->message);
        $this->assertFalse(SystemSetting::boolean('routing.enabled', false));
    }

    public function test_payload_command_returns_the_immutable_database_snapshot_not_request_parameters(): void
    {
        $actor = $this->user('osrm-payload@example.test', ['system.routing.manage']);
        $response = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', $this->installPayload())
            ->assertStatus(202);
        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));

        $this->assertSame(0, Artisan::call('dis:osrm-operation:payload', ['operationId' => $operation->id]));
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'version',
            'operation_id',
            'action',
            'actor_id',
            'sources',
            'health_coordinate',
        ], array_keys($payload));
        $this->assertSame($operation->id, $payload['operation_id']);
        $this->assertSame($this->sourceSet(), $payload['sources']);
        $this->assertArrayNotHasKey('source_url', $payload);
        $this->assertArrayNotHasKey('source_md5', $payload);
        $this->assertArrayNotHasKey('source_sha256', $payload);
        $this->assertArrayNotHasKey('source_manifest', $payload);
        $this->assertArrayNotHasKey('source_set_sha256', $payload);
    }

    public function test_root_worker_can_release_a_malformed_request_by_filename_and_stale_queued_requests_recover(): void
    {
        $actor = $this->user('osrm-recovery@example.test', ['system.routing.manage']);
        $response = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', $this->installPayload())
            ->assertStatus(202);
        $operation = OsrmOperation::query()->findOrFail($response->json('data.operation.id'));

        $this->assertSame(0, Artisan::call('dis:osrm-operation:fail-request', [
            'requestId' => $operation->request_id,
            'reason' => 'rejected',
        ]));
        $this->assertSame('failed', $operation->refresh()->state);
        $this->assertNull($operation->active_key);

        $second = $this->asAdminClient($actor)
            ->postJson('/api/admin/routing/osrm/operations', [
                ...$this->installPayload(),
            ])
            ->assertStatus(202);
        $stale = OsrmOperation::query()->findOrFail($second->json('data.operation.id'));
        $stale->forceFill([
            'created_at' => now()->subHours(23),
            'updated_at' => now()->subHours(23),
        ])->save();

        $this->asAdminClient($actor)->getJson('/api/admin/routing/osrm')->assertOk();
        $this->assertSame('queued', $stale->refresh()->state);
        $this->assertSame(OsrmOperation::ACTIVE_KEY, $stale->active_key);

        $stale->forceFill([
            'created_at' => now()->subDay()->subMinute(),
            'updated_at' => now()->subDay()->subMinute(),
        ])->save();
        $this->asAdminClient($actor)->getJson('/api/admin/routing/osrm')->assertOk();
        $this->assertSame('failed', $stale->refresh()->state);
        $this->assertSame(124, $stale->exit_code);
        $this->assertNull($stale->active_key);
    }

    /** @return array<string, mixed> */
    private function installPayload(): array
    {
        return [
            'action' => 'install_activate',
        ];
    }

    private function user(string $email, array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'OSRM Test User',
            'first_name' => 'OSRM',
            'last_name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'osrm-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'OSRM test role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'OSRM test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('OSRM admin test', ['*', 'client:admin'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function putSetting(string $key, mixed $value): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'is_sensitive' => false],
        );
    }

    /** @return list<array{id: string, label: string, latest_url: string}> */
    private function configuredSources(): array
    {
        return [
            [
                'id' => 'netherlands',
                'label' => 'Nederland',
                'latest_url' => 'https://download.geofabrik.de/europe/netherlands-latest.osm.pbf',
            ],
            [
                'id' => 'belgium',
                'label' => 'België',
                'latest_url' => 'https://download.geofabrik.de/europe/belgium-latest.osm.pbf',
            ],
        ];
    }

    /** @return list<array{id: string, latest_url: string}> */
    private function sourceSet(): array
    {
        return array_map(
            static fn (array $source): array => [
                'id' => $source['id'],
                'latest_url' => $source['latest_url'],
            ],
            $this->configuredSources(),
        );
    }

    private function sourceSetSha256(): string
    {
        return hash('sha256', json_encode(
            $this->sourceSet(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @return array{source_set_sha256: string, snapshot_date: string, source_timestamp: string, sources: list<array{id: string, filename: string, version_url: string, md5: string, size_bytes: int}>}
     */
    private function sourceManifest(
        string $netherlandsMd5 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        string $belgiumMd5 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ): array {
        return [
            'source_set_sha256' => $this->sourceSetSha256(),
            'snapshot_date' => '2026-07-15',
            'source_timestamp' => '2026-07-15T20:21:10Z',
            'sources' => [
                [
                    'id' => 'netherlands',
                    'filename' => 'netherlands-260715.osm.pbf',
                    'version_url' => 'https://download.geofabrik.de/europe/netherlands-260715.osm.pbf',
                    'md5' => $netherlandsMd5,
                    'size_bytes' => 4_500_000_000,
                ],
                [
                    'id' => 'belgium',
                    'filename' => 'belgium-260715.osm.pbf',
                    'version_url' => 'https://download.geofabrik.de/europe/belgium-260715.osm.pbf',
                    'md5' => $belgiumMd5,
                    'size_bytes' => 700_000_000,
                ],
            ],
        ];
    }

    private function writeReadyRuntimeStatus(
        array $manifest,
        bool $healthy = true,
        string $healthCoordinate = '5.1214,52.0907',
    ): void {
        $this->writeProtectedFile($this->globalStatusPath, (string) json_encode([
            'version' => 2,
            'state' => $healthy ? 'ready' : 'degraded',
            'installed' => true,
            'healthy' => $healthy,
            'package' => [
                'version' => '5.27.1-1',
                'verified_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'dataset' => [
                'source_manifest' => $manifest,
                'legacy_sha256' => null,
                'imported_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'health_coordinate' => $healthCoordinate,
            ],
            'service_state' => $healthy ? 'active' : 'failed',
            'detail' => $healthy
                ? 'Local road-network routing is available.'
                : 'An active OSRM dataset exists, but the local readiness check failed.',
            'updated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function writeReadyLegacyRuntimeStatus(string $sha256): void
    {
        $this->writeProtectedFile($this->globalStatusPath, (string) json_encode([
            'version' => 2,
            'state' => 'ready',
            'installed' => true,
            'healthy' => true,
            'package' => [
                'version' => '5.27.1-1',
                'verified_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'dataset' => [
                'source_manifest' => null,
                'legacy_sha256' => $sha256,
                'imported_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'health_coordinate' => '5.1214,52.0907',
            ],
            'service_state' => 'active',
            'detail' => 'Local road-network routing is available.',
            'updated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** @param array<string, mixed> $overrides */
    private function writeOperationStatus(OsrmOperation $operation, array $overrides): void
    {
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->writeProtectedFile(
            $this->stateRoot.'/results/'.$operation->id.'.status.json',
            (string) json_encode([
                'version' => 2,
                'operation_id' => $operation->id,
                'action' => $operation->action,
                'state' => 'running',
                'stage' => 'validating',
                'message' => 'OSRM-bewerking actief.',
                'progress_percent' => null,
                'started_at' => $now,
                'updated_at' => $now,
                'finished_at' => null,
                'exit_code' => null,
                'active_source_manifest' => null,
                ...$overrides,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    private function writeProtectedFile(string $path, string $contents): void
    {
        file_put_contents($path, $contents, LOCK_EX);
        chmod($path, 0640);
    }
}
