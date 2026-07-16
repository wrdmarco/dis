<?php

namespace Tests\Feature;

use App\Contracts\DispatchNotificationQueue;
use App\Contracts\PushProvider;
use App\Jobs\SendFcmNotification;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\User;
use App\Services\DispatchPushOutboxService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class DispatchPushOutboxLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhausted_job_cycle_returns_outbox_to_pending_with_bounded_backoff(): void
    {
        [$token, $dispatch, $outbox] = $this->outboxFixture('release');
        $outbox->forceFill(['queued_at' => now()])->save();
        $job = $this->job($token, $dispatch, $outbox);

        $job->failed(new RuntimeException('Simulated exhausted provider retry cycle.'));

        $outbox->refresh();
        $this->assertNull($outbox->queued_at);
        $this->assertNull($outbox->delivered_at);
        $this->assertNull($outbox->cancelled_at);
        $this->assertSame(1, $outbox->attempts);
        $this->assertSame('delivery_retry_exhausted', $outbox->last_error_code);
        $this->assertTrue($outbox->available_at->betweenIncluded(now()->addSeconds(59), now()->addSeconds(61)));

        $recordingQueue = $this->recordingQueue();
        $this->app->instance(DispatchNotificationQueue::class, $recordingQueue);
        $this->assertSame(
            ['queued' => 0, 'failed' => 0, 'cancelled' => 0],
            app(DispatchPushOutboxService::class)->flushPending(),
        );
        $this->travel(61)->seconds();
        $this->assertSame(
            ['queued' => 1, 'failed' => 0, 'cancelled' => 0],
            app(DispatchPushOutboxService::class)->flushPending(),
        );
        $this->assertSame([(string) $outbox->id], $recordingQueue->outboxIds);
    }

    public function test_success_and_permanent_rejection_finish_the_outbox_lifecycle(): void
    {
        [$successToken, $successDispatch, $successOutbox] = $this->outboxFixture('success');
        $successOutbox->forceFill(['queued_at' => now()])->save();

        $this->job($successToken, $successDispatch, $successOutbox)->handle(
            $this->pushProviderResponse(200, ['name' => 'messages/test-success']),
            app(DispatchPushOutboxService::class),
        );

        $successOutbox->refresh();
        $this->assertNotNull($successOutbox->delivered_at);
        $this->assertNull($successOutbox->cancelled_at);
        [$rejectedToken, $rejectedDispatch, $rejectedOutbox] = $this->outboxFixture('rejected');
        $rejectedOutbox->forceFill(['queued_at' => now()])->save();

        $this->job($rejectedToken, $rejectedDispatch, $rejectedOutbox)->handle(
            $this->pushProviderResponse(403, ['error' => ['status' => 'PERMISSION_DENIED']]),
            app(DispatchPushOutboxService::class),
        );

        $rejectedOutbox->refresh();
        $this->assertNull($rejectedOutbox->delivered_at);
        $this->assertNotNull($rejectedOutbox->cancelled_at);
        $this->assertSame('provider_rejected', $rejectedOutbox->last_error_code);
    }

    public function test_stale_queue_lease_is_requeued_but_recent_lease_is_left_to_its_worker(): void
    {
        [, , $staleOutbox] = $this->outboxFixture('stale-lease');
        [, , $recentOutbox] = $this->outboxFixture('recent-lease');
        $staleOutbox->forceFill(['queued_at' => now()->subMinutes(16)])->save();
        $recentOutbox->forceFill(['queued_at' => now()->subMinutes(14)])->save();
        $recentQueuedAt = $recentOutbox->queued_at;
        $recordingQueue = $this->recordingQueue();
        $this->app->instance(DispatchNotificationQueue::class, $recordingQueue);

        $result = app(DispatchPushOutboxService::class)->flushPending();

        $this->assertSame(['queued' => 1, 'failed' => 0, 'cancelled' => 0], $result);
        $this->assertSame([(string) $staleOutbox->id], $recordingQueue->outboxIds);
        $this->assertTrue($staleOutbox->refresh()->queued_at->greaterThan(now()->subMinute()));
        $this->assertTrue($recentOutbox->refresh()->queued_at->equalTo($recentQueuedAt));
    }

    public function test_pruning_removes_only_terminal_outbox_rows(): void
    {
        [, , $delivered] = $this->outboxFixture('prune-delivered');
        [, , $cancelled] = $this->outboxFixture('prune-cancelled');
        [, , $queued] = $this->outboxFixture('prune-queued');
        [, , $pending] = $this->outboxFixture('prune-pending');
        foreach ([$delivered, $cancelled, $queued, $pending] as $notification) {
            $notification->forceFill(['created_at' => now()->subDays(100)])->save();
        }
        $delivered->forceFill(['queued_at' => now()->subDays(100), 'delivered_at' => now()->subDays(99)])->save();
        $cancelled->forceFill(['cancelled_at' => now()->subDays(99)])->save();
        $queued->forceFill(['queued_at' => now()->subMinutes(5)])->save();

        $this->artisan('dis:prune-operational-data')->assertSuccessful();

        $this->assertDatabaseMissing('dispatch_push_outbox', ['id' => $delivered->id]);
        $this->assertDatabaseMissing('dispatch_push_outbox', ['id' => $cancelled->id]);
        $this->assertDatabaseHas('dispatch_push_outbox', ['id' => $queued->id]);
        $this->assertDatabaseHas('dispatch_push_outbox', ['id' => $pending->id]);
    }

    /** @return array{FcmToken, DispatchRequest, DispatchPushOutbox} */
    private function outboxFixture(string $suffix): array
    {
        $user = User::query()->create([
            'name' => 'Outbox '.$suffix,
            'first_name' => 'Outbox',
            'last_name' => $suffix,
            'email' => "outbox-{$suffix}@example.test",
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
        ]);
        $token = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'outbox-'.$suffix,
            'token' => 'outbox-token-'.$suffix,
            'token_hash' => hash('sha256', 'outbox-token-'.$suffix),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $incident = Incident::query()->create([
            'reference' => 'OUTBOX-'.strtoupper($suffix),
            'title' => 'Outbox lifecycle test',
            'priority' => 'normal',
            'status' => 'dispatching',
            'is_test' => false,
            'created_by' => $user->id,
            'created_by_name' => $user->name,
            'created_by_email' => $user->email,
            'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $user->id,
            'requested_by_name' => $user->name,
            'requested_by_email' => $user->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Outbox lifecycle test',
            'sent_at' => now(),
        ]);
        $outbox = DispatchPushOutbox::query()->create([
            'deduplication_key' => hash('sha256', $suffix),
            'dispatch_request_id' => $dispatch->id,
            'fcm_token_id' => $token->id,
            'message_type' => 'dispatch_request',
            'title' => 'Alarm',
            'body' => 'Open de app.',
            'data' => ['type' => 'dispatch_request', 'dispatch_id' => (string) $dispatch->id],
            'available_at' => now(),
        ]);

        return [$token, $dispatch, $outbox];
    }

    private function job(FcmToken $token, DispatchRequest $dispatch, DispatchPushOutbox $outbox): SendFcmNotification
    {
        return new SendFcmNotification(
            (string) $token->id,
            'dispatch_request',
            'Alarm',
            'Open de app.',
            ['type' => 'dispatch_request', 'dispatch_id' => (string) $dispatch->id],
            (string) $dispatch->id,
            (string) $outbox->id,
        );
    }

    private function recordingQueue(): DispatchNotificationQueue
    {
        return new class implements DispatchNotificationQueue
        {
            /** @var list<string> */
            public array $outboxIds = [];

            public function enqueue(DispatchPushOutbox $notification): void
            {
                $this->outboxIds[] = (string) $notification->id;
            }
        };
    }

    /** @param array<string, mixed> $payload */
    private function pushProviderResponse(int $status, array $payload): PushProvider
    {
        return new class($status, $payload) implements PushProvider
        {
            /** @param array<string, mixed> $payload */
            public function __construct(private readonly int $status, private readonly array $payload) {}

            public function send(FcmToken $token, string $title, string $body, array $data = []): ClientResponse
            {
                return new ClientResponse(new PsrResponse(
                    $this->status,
                    ['Content-Type' => 'application/json'],
                    json_encode($this->payload, JSON_THROW_ON_ERROR),
                ));
            }
        };
    }
}
