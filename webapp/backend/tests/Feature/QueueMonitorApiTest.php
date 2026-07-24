<?php

namespace Tests\Feature;

use App\Contracts\QueueTransportMetrics;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\IncidentSpeechPreparation;
use App\Models\Permission;
use App\Models\PushQueueWorkItem;
use App\Models\Role;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechPreparedPhrase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

final class QueueMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_monitor_requires_authentication_and_health_permission(): void
    {
        $this->getJson('/api/admin/queues')->assertUnauthorized();

        $this->asAdminClient($this->user('queue-denied@example.test'))
            ->getJson('/api/admin/queues')
            ->assertForbidden();
    }

    public function test_queue_monitor_returns_bounded_safe_speech_work_without_source_text(): void
    {
        $this->mockTransport();
        $viewer = $this->user('queue-viewer@example.test', ['system.health.view']);
        $secretText = 'Geheime meldtekst die nooit in de queue-monitor hoort';

        SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'duration-test'),
            'category' => 'segment',
            'semantic_hmac' => hash('sha256', 'semantic-test'),
            'display_text' => $secretText,
            'status' => 'ready',
            'synthesis_duration_ms' => 4321,
        ]);
        SpeechPreparedPhrase::query()->create([
            'kind' => 'fixed_phrase',
            'identity_hmac' => hash('sha256', 'unknown-state'),
            'display_text' => $secretText,
            'status' => 'retired',
            'progress_percent' => 100,
        ]);
        SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'failed-without-progress'),
            'category' => 'segment',
            'semantic_hmac' => hash('sha256', 'failed-semantic'),
            'status' => 'failed',
            'error_code' => 'synthesis_failed',
        ]);

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=speech&per_page=10')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.queues.0.key', 'speech')
            ->assertJsonPath('data.queues.0.configured_parallelism', 1)
            ->assertJsonPath('data.queues.0.transport_pending_count', 3)
            ->assertJsonPath('data.queues.0.states.total', 3)
            ->assertJsonPath('data.items.0.queue', 'speech')
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'refresh_after_seconds',
                    'summary',
                    'queues',
                    'items' => [[
                        'id',
                        'queue',
                        'workload_type',
                        'label',
                        'state',
                        'progress_percent',
                        'queued_at',
                        'started_at',
                        'next_attempt_at',
                        'finished_at',
                        'attempts',
                        'error_code',
                        'duration_ms',
                    ]],
                ],
                'meta' => ['current_page', 'per_page', 'total', 'last_page', 'is_truncated'],
            ]);

        $items = collect($response->json('data.items'));
        $duration = $items->first(
            fn (array $item): bool => $item['workload_type'] === 'speech_audio_fragment'
                && $item['duration_ms'] === 4321,
        );
        $failedWithoutProgress = $items->first(
            fn (array $item): bool => $item['workload_type'] === 'speech_audio_fragment'
                && $item['state'] === 'failed',
        );
        $unknown = $items->firstWhere('workload_type', 'speech_prepared_phrase');
        $this->assertSame(4321, $duration['duration_ms']);
        $this->assertNull($duration['started_at']);
        $this->assertNull($failedWithoutProgress['progress_percent']);
        $this->assertSame('failed', $unknown['state']);
        $this->assertSame(100, $unknown['progress_percent']);
        $this->assertStringNotContainsString($secretText, (string) $response->getContent());
        $this->assertStringNotContainsString('display_text', (string) $response->getContent());

        $failedItems = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=speech&state=failed&per_page=10')
            ->assertOk()
            ->json('data.items');
        $this->assertNotNull(
            collect($failedItems)->firstWhere('workload_type', 'speech_prepared_phrase'),
            'Een onbekende terminale status moet ook via het fail-closed foutfilter zichtbaar blijven.',
        );
    }

    public function test_transport_count_honestly_represents_non_outbox_push_jobs_without_payload_details(): void
    {
        $this->mockTransport(pushPending: 7);
        $viewer = $this->user('queue-push-viewer@example.test', ['system.health.view']);

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push')
            ->assertOk()
            ->assertJsonPath('data.queues.0.key', 'push')
            ->assertJsonPath('data.queues.0.configured_parallelism', 4)
            ->assertJsonPath('data.queues.0.transport_pending_count', 7)
            ->assertJsonCount(0, 'data.items');

        $this->assertStringNotContainsString('payload', (string) $response->getContent());
        $this->assertStringNotContainsString('token', (string) $response->getContent());
    }

    public function test_queue_monitor_lists_safe_non_outbox_push_ledger_items(): void
    {
        $this->mockTransport(pushPending: 1);
        $viewer = $this->user('queue-ledger-viewer@example.test', ['system.health.view']);
        PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', 'opaque-transport-id'),
            'safe_message_type' => 'manual_admin',
            'status' => PushQueueWorkItem::STATUS_RETRYING,
            'attempts' => 2,
            'error_code' => 'queue_retry_scheduled',
            'queued_at' => now()->subSeconds(4),
            'processing_started_at' => now()->subSeconds(3),
            'next_attempt_at' => now()->addSeconds(12),
        ]);

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push')
            ->assertOk()
            ->assertJsonPath('data.summary.retrying', 1)
            ->assertJsonPath('data.items.0.label', 'Handmatige pushmelding')
            ->assertJsonPath('data.items.0.state', 'retrying')
            ->assertJsonPath('data.items.0.attempts', 2);

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('queue_job_id', $content);
        $this->assertStringNotContainsString('opaque-transport-id', $content);
        $this->assertStringNotContainsString('payload', $content);
    }

    public function test_current_outbox_lifecycle_overlay_drives_retry_items_and_counts(): void
    {
        $this->mockTransport(pushPending: 2);
        $viewer = $this->user('queue-outbox-overlay@example.test', ['system.health.view']);
        $normalRetry = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(60),
            'processing_started_at' => now()->subSeconds(55),
            'retry_at' => now()->addSeconds(5),
            'last_error_code' => 'delivery_retry_scheduled',
        ]);
        $this->linkedPushWorkItem($normalRetry, 'normal-retry-older', [
            'status' => PushQueueWorkItem::STATUS_PROCESSING,
            'attempts' => 1,
            'queued_at' => now()->subSeconds(59),
            'processing_started_at' => now()->subSeconds(54),
        ], now()->subSeconds(50));
        $this->linkedPushWorkItem($normalRetry, 'normal-retry-current', [
            'status' => PushQueueWorkItem::STATUS_RETRYING,
            'attempts' => 2,
            'error_code' => 'queue_retry_scheduled',
            'queued_at' => now()->subSeconds(59),
            'processing_started_at' => now()->subSeconds(44),
            'next_attempt_at' => now()->addSeconds(30),
        ], now()->subSeconds(40));

        $timeoutRetry = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(30),
            'processing_started_at' => now()->subSeconds(20),
        ]);
        $this->linkedPushWorkItem($timeoutRetry, 'timeout-retry-current', [
            'status' => PushQueueWorkItem::STATUS_RETRYING,
            'attempts' => 1,
            'error_code' => 'queue_timeout_retry_scheduled',
            'queued_at' => now()->subSeconds(29),
            'processing_started_at' => now()->subSeconds(20),
            'next_attempt_at' => now()->addSeconds(40),
        ], now()->subSeconds(15));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push&state=retrying&per_page=100')
            ->assertOk()
            ->assertJsonPath('data.summary.retrying', 2)
            ->assertJsonPath('data.summary.processing', 0)
            ->assertJsonPath('meta.total', 2);
        $lifecycleQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'push_queue_work_items'));
        DB::disableQueryLog();

        $items = collect($response->json('data.items'))->keyBy('id');
        $this->assertCount(
            4,
            $lifecycleQueries,
            'The fixed count-and-item query set must not grow with the number of outbox rows.',
        );
        $this->assertCount(2, $items);
        $this->assertSame('retrying', $items[(string) $normalRetry->id]['state']);
        $this->assertSame(2, $items[(string) $normalRetry->id]['attempts']);
        $this->assertSame('queue_retry_scheduled', $items[(string) $normalRetry->id]['error_code']);
        $this->assertNotNull($items[(string) $normalRetry->id]['started_at']);
        $this->assertNotNull($items[(string) $normalRetry->id]['next_attempt_at']);
        $this->assertSame('retrying', $items[(string) $timeoutRetry->id]['state']);
        $this->assertSame(1, $items[(string) $timeoutRetry->id]['attempts']);
        $this->assertSame(
            'queue_timeout_retry_scheduled',
            $items[(string) $timeoutRetry->id]['error_code'],
        );
        $this->assertNotNull($items[(string) $timeoutRetry->id]['next_attempt_at']);
    }

    public function test_terminal_between_cycle_and_new_claim_outboxes_ignore_ineligible_lifecycle_rows(): void
    {
        $this->mockTransport(pushPending: 1);
        $viewer = $this->user('queue-outbox-precedence@example.test', ['system.health.view']);

        $exhausted = $this->pushOutbox($viewer, [
            'queued_at' => null,
            'processing_started_at' => null,
            'retry_at' => now()->addMinute(),
            'attempts' => 1,
            'last_error_code' => 'delivery_retry_exhausted',
        ]);
        $this->linkedPushWorkItem($exhausted, 'exhausted-old-cycle', [
            'status' => PushQueueWorkItem::STATUS_FAILED,
            'attempts' => 4,
            'error_code' => 'queue_job_failed',
            'finished_at' => now()->subSecond(),
        ], now()->subSeconds(2));

        $cancelled = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(20),
            'cancelled_at' => now()->subSeconds(2),
            'attempts' => 0,
            'last_error_code' => 'stale_dispatch_phase',
        ]);
        $this->linkedPushWorkItem($cancelled, 'cancelled-late-ledger', [
            'status' => PushQueueWorkItem::STATUS_RETRYING,
            'attempts' => 3,
            'error_code' => 'queue_retry_scheduled',
            'next_attempt_at' => now()->addSeconds(30),
        ], now()->subSecond());

        $delivered = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(20),
            'delivered_at' => now()->subSecond(),
            'attempts' => 0,
        ]);
        $this->linkedPushWorkItem($delivered, 'delivered-late-ledger', [
            'status' => PushQueueWorkItem::STATUS_FAILED,
            'attempts' => 4,
            'error_code' => 'queue_job_failed',
            'finished_at' => now(),
        ], now());

        $newClaim = $this->pushOutbox($viewer, [
            'queued_at' => now()->subSeconds(5),
            'processing_started_at' => now()->subSeconds(4),
            'attempts' => 2,
            'last_error_code' => 'outbox_processing',
        ]);
        $this->linkedPushWorkItem($newClaim, 'historical-ledger', [
            'status' => PushQueueWorkItem::STATUS_FAILED,
            'attempts' => 4,
            'error_code' => 'queue_job_failed',
            'finished_at' => now()->subSeconds(8),
        ], now()->subSeconds(10));

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push&per_page=100')
            ->assertOk()
            ->assertJsonPath('data.summary.retrying', 1)
            ->assertJsonPath('data.summary.cancelled', 1)
            ->assertJsonPath('data.summary.completed', 1)
            ->assertJsonPath('data.summary.processing', 1)
            ->assertJsonPath('data.summary.failed', 0);

        $items = collect($response->json('data.items'))->keyBy('id');
        $this->assertCount(4, $items);
        $this->assertSame('retrying', $items[(string) $exhausted->id]['state']);
        $this->assertSame(1, $items[(string) $exhausted->id]['attempts']);
        $this->assertSame(
            'delivery_retry_exhausted',
            $items[(string) $exhausted->id]['error_code'],
        );
        $this->assertSame('cancelled', $items[(string) $cancelled->id]['state']);
        $this->assertSame(0, $items[(string) $cancelled->id]['attempts']);
        $this->assertSame('stale_dispatch_phase', $items[(string) $cancelled->id]['error_code']);
        $this->assertSame('completed', $items[(string) $delivered->id]['state']);
        $this->assertSame(0, $items[(string) $delivered->id]['attempts']);
        $this->assertNull($items[(string) $delivered->id]['error_code']);
        $this->assertSame('processing', $items[(string) $newClaim->id]['state']);
        $this->assertSame(2, $items[(string) $newClaim->id]['attempts']);
        $this->assertSame('outbox_processing', $items[(string) $newClaim->id]['error_code']);
    }

    public function test_incident_speech_preparations_are_mapped_without_incident_content(): void
    {
        $this->mockTransport();
        $viewer = $this->user('queue-incident-speech@example.test', ['system.health.view']);
        $secretIncidentTitle = 'Vertrouwelijk incident aan de geheime straat';
        $incident = Incident::query()->create([
            'reference' => 'QUEUE-SPEECH-001',
            'title' => $secretIncidentTitle,
            'priority' => 'normal',
            'status' => 'draft',
            'created_by' => $viewer->id,
        ]);
        $expectedStates = [
            IncidentSpeechPreparation::STATUS_QUEUED => 'queued',
            IncidentSpeechPreparation::STATUS_PROCESSING => 'processing',
            IncidentSpeechPreparation::STATUS_READY => 'completed',
            IncidentSpeechPreparation::STATUS_FAILED => 'failed',
            IncidentSpeechPreparation::STATUS_CANCELLED => 'cancelled',
            IncidentSpeechPreparation::STATUS_DISABLED => 'cancelled',
            IncidentSpeechPreparation::STATUS_NOT_SCHEDULED => 'cancelled',
        ];
        foreach ($expectedStates as $status => $mappedState) {
            IncidentSpeechPreparation::query()->create([
                'incident_id' => $incident->id,
                'phase' => str_replace('_', '-', $status),
                'source_fingerprint_hmac' => hash('sha256', $status),
                'status' => $status,
                'progress_percent' => $status === IncidentSpeechPreparation::STATUS_PROCESSING ? 47 : 0,
                'error_code' => $status === IncidentSpeechPreparation::STATUS_FAILED ? 'prewarm_failed' : null,
            ]);
        }

        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=speech&per_page=100')
            ->assertOk();
        $items = collect($response->json('data.items'))
            ->where('workload_type', 'incident_speech_preparation');
        $this->assertCount(count($expectedStates), $items);
        foreach (array_count_values($expectedStates) as $state => $count) {
            $this->assertCount($count, $items->where('state', $state));
        }
        $this->assertSame(
            47,
            $items->firstWhere('state', 'processing')['progress_percent'],
        );
        $this->assertStringNotContainsString($secretIncidentTitle, (string) $response->getContent());
        $this->assertStringNotContainsString((string) $incident->id, (string) $response->getContent());
    }

    public function test_cache_hits_do_not_resurface_old_terminal_audio_as_new_queue_work(): void
    {
        $this->mockTransport();
        $viewer = $this->user('queue-cache-created-at@example.test', ['system.health.view']);
        $entry = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'old-hit-cache'),
            'category' => 'segment',
            'semantic_hmac' => hash('sha256', 'old-hit-semantic'),
            'status' => 'ready',
        ]);
        $entry->timestamps = false;
        $entry->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now(),
            'last_used_at' => now(),
            'hit_count' => 99,
        ])->save();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=speech&per_page=100')
            ->assertOk();
        $cacheQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'speech_cache_entries'));
        DB::disableQueryLog();

        $this->assertNotContains(
            (string) $entry->id,
            collect($response->json('data.items'))->pluck('id')->all(),
        );
        $this->assertNotEmpty($cacheQueries);
        foreach ($cacheQueries as $sql) {
            $this->assertStringContainsString('"created_at" >=', $sql);
            $this->assertStringNotContainsString('"updated_at" >=', $sql);
        }
    }

    public function test_queue_monitor_rejects_unbounded_pagination_and_invalid_filters(): void
    {
        $viewer = $this->user('queue-validation@example.test', ['system.health.view']);

        $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?per_page=101&page=2001&queue=redis&state=reserved')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure([
                'error' => [
                    'details' => ['per_page', 'page', 'queue', 'state'],
                ],
            ]);
    }

    public function test_push_only_poll_does_not_query_speech_domain_tables(): void
    {
        $this->mockTransport(pushPending: 1);
        $viewer = $this->user('queue-query-scope@example.test', ['system.health.view']);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->asAdminClient($viewer)
            ->getJson('/api/admin/queues?queue=push')
            ->assertOk();

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        $this->assertStringNotContainsString('speech_', strtolower($sql));
        DB::disableQueryLog();
    }

    private function mockTransport(int $pushPending = 2, int $speechPending = 3): void
    {
        $transport = Mockery::mock(QueueTransportMetrics::class);
        $transport->shouldReceive('pendingCount')->with('push', 'push')->zeroOrMoreTimes()->andReturn($pushPending);
        $transport->shouldReceive('pendingCount')->with('speech', 'speech')->zeroOrMoreTimes()->andReturn($speechPending);
        $transport->shouldReceive('failedCount')->with('push')->zeroOrMoreTimes()->andReturn(0);
        $transport->shouldReceive('failedCount')->with('speech')->zeroOrMoreTimes()->andReturn(0);
        $this->app->instance(QueueTransportMetrics::class, $transport);
    }

    /** @param array<string, mixed> $attributes */
    private function pushOutbox(User $user, array $attributes = []): DispatchPushOutbox
    {
        $suffix = strtolower((string) str()->ulid());
        $incident = Incident::query()->create([
            'reference' => 'QUEUE-'.$suffix,
            'title' => 'Queue monitor fixture',
            'priority' => 'normal',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $user->id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Queue monitor fixture',
        ]);
        $providerToken = 'queue-provider-'.$suffix;
        $token = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'queue-device-'.$suffix,
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
        ]);

        return DispatchPushOutbox::query()->create([
            'deduplication_key' => hash('sha256', 'queue-outbox-'.$suffix),
            'dispatch_request_id' => $dispatch->id,
            'fcm_token_id' => $token->id,
            'message_type' => 'dispatch_request',
            'title' => 'Queue monitor fixture',
            'body' => 'Queue monitor fixture',
            'data' => [],
            'available_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function linkedPushWorkItem(
        DispatchPushOutbox $outbox,
        string $jobId,
        array $attributes,
        \DateTimeInterface $createdAt,
    ): PushQueueWorkItem {
        $item = PushQueueWorkItem::query()->create([
            'queue_job_id' => hash('sha256', $jobId),
            'safe_message_type' => 'dispatch_request',
            'dispatch_push_outbox_id' => $outbox->id,
            ...$attributes,
        ]);
        $item->timestamps = false;
        $item->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $item;
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'Queue Monitor Test',
            'first_name' => 'Queue',
            'last_name' => 'Monitor Test',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'queue-monitor-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Queue monitor test role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'Queue monitor test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Queue monitor web test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
