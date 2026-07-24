<?php

namespace Tests\Feature;

use App\Contracts\PushProvider;
use App\Jobs\SendFcmNotification;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\Team;
use App\Models\User;
use App\Services\DispatchPushOutboxService;
use App\Services\DispatchService;
use App\Services\IncidentService;
use App\Support\PushNotificationIdentity;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PreannouncementAlarmTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preannouncement_without_dispatch_context_is_not_delivered(): void
    {
        $pilot = $this->user('contextless-pilot@example.test', 'Contextless Pilot', pushEnabled: true);
        $token = FcmToken::query()->create([
            'user_id' => $pilot->id,
            'personal_access_token_id' => $this->operatorAccessTokenId($pilot, 'Contextless device'),
            'device_id' => 'contextless-device',
            'token' => 'contextless-token',
            'token_hash' => hash('sha256', 'contextless-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $provider = new class implements PushProvider
        {
            public int $sendCount = 0;

            /** @param array<string, string> $data */
            public function send(FcmToken $token, string $title, string $body, array $data = []): ClientResponse
            {
                $this->sendCount++;

                return new ClientResponse(new PsrResponse(200));
            }
        };
        $job = new SendFcmNotification(
            $token->id,
            'incident_preannouncement',
            'Vooraankondiging',
            'Ben je beschikbaar?',
            [
                'type' => 'incident_preannouncement',
                'action_mode' => 'availability',
            ],
        );

        $job->handle($provider, app(DispatchPushOutboxService::class));

        $this->assertSame(0, $provider->sendCount);
        $this->assertDatabaseMissing('push_delivery_logs', [
            'fcm_token_id' => $token->id,
            'message_type' => 'incident_preannouncement',
        ]);
    }

    public function test_delayed_preannouncement_is_not_delivered_after_incident_cancellation(): void
    {
        $actor = $this->user('cancelled-prealarm-actor@example.test', 'Cancelled Actor');
        $pilot = $this->user('cancelled-prealarm-pilot@example.test', 'Cancelled Pilot', pushEnabled: true);
        $token = FcmToken::query()->create([
            'user_id' => $pilot->id,
            'personal_access_token_id' => $this->operatorAccessTokenId($pilot, 'Cancelled prealarm device'),
            'device_id' => 'cancelled-prealarm-device',
            'token' => 'cancelled-prealarm-token',
            'token_hash' => hash('sha256', 'cancelled-prealarm-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $incident = Incident::query()->create([
            'reference' => 'CANCELLED-PREALARM-001',
            'title' => 'Geannuleerde vooraankondiging',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => false,
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
            'created_by_email' => $actor->email,
            'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
            'requested_by_email' => $actor->email,
            'status' => 'draft',
            'priority' => 'normal',
            'message' => 'Ben je beschikbaar?',
        ]);
        $incident->update(['status' => 'cancelled', 'closed_at' => now()]);
        $provider = new class implements PushProvider
        {
            public int $sendCount = 0;

            /** @param array<string, string> $data */
            public function send(FcmToken $token, string $title, string $body, array $data = []): ClientResponse
            {
                $this->sendCount++;

                return new ClientResponse(new PsrResponse(200));
            }
        };
        $job = new SendFcmNotification(
            (string) $token->id,
            'incident_preannouncement',
            'Vooraankondiging',
            'Ben je beschikbaar?',
            [
                'type' => 'dispatch_update',
                'action_mode' => 'availability',
                'incident_id' => (string) $incident->id,
                'dispatch_id' => (string) $dispatch->id,
            ],
            (string) $dispatch->id,
        );

        $job->handle($provider, app(DispatchPushOutboxService::class));

        $this->assertSame('draft', $dispatch->refresh()->status);
        $this->assertSame(0, $provider->sendCount);
        $this->assertDatabaseMissing('push_delivery_logs', [
            'fcm_token_id' => $token->id,
            'dispatch_request_id' => $dispatch->id,
            'message_type' => 'incident_preannouncement',
        ]);
    }

    public function test_legacy_cancellation_without_incident_context_remains_deliverable_during_a_rolling_update(): void
    {
        $pilot = $this->user('legacy-cancellation-pilot@example.test', 'Legacy Pilot', pushEnabled: true);
        $token = FcmToken::query()->create([
            'user_id' => $pilot->id,
            'personal_access_token_id' => $this->operatorAccessTokenId($pilot, 'Legacy cancellation device'),
            'device_id' => 'legacy-cancellation-device',
            'token' => 'legacy-cancellation-token',
            'token_hash' => hash('sha256', 'legacy-cancellation-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $provider = new class implements PushProvider
        {
            public int $sendCount = 0;

            /** @param array<string, string> $data */
            public function send(FcmToken $token, string $title, string $body, array $data = []): ClientResponse
            {
                $this->sendCount++;

                return new ClientResponse(new PsrResponse(200));
            }
        };
        $job = new SendFcmNotification(
            (string) $token->id,
            'incident_cancelled',
            'Geannuleerd',
            'De vooraankondiging is geannuleerd.',
            ['type' => 'incident_cancelled'],
        );

        $job->handle($provider, app(DispatchPushOutboxService::class));

        $this->assertSame(1, $provider->sendCount);
        $this->assertDatabaseHas('push_delivery_logs', [
            'fcm_token_id' => $token->id,
            'message_type' => 'incident_cancelled',
            'status' => 'sent',
        ]);
    }

    public function test_first_alarm_after_preannouncement_rejects_a_delayed_preannouncement_job(): void
    {
        Queue::fake();
        $actor = $this->user('transition-actor@example.test', 'Transition Actor');
        $pilot = $this->user('transition-pilot@example.test', 'Transition Pilot', pushEnabled: true);
        $team = Team::query()->create([
            'code' => 'PREALARM-TRANSITION',
            'name' => 'Prealarm Transition',
            'type' => 'base',
            'is_operational' => true,
        ]);
        $team->users()->attach($pilot->id, ['created_at' => now()]);
        $pilot->forceFill([
            'home_city' => 'Teststad',
            'home_latitude' => 52.100000,
            'home_longitude' => 5.100000,
        ])->save();
        $token = FcmToken::query()->create([
            'user_id' => $pilot->id,
            'personal_access_token_id' => $this->operatorAccessTokenId($pilot, 'Transition device'),
            'device_id' => 'transition-device',
            'token' => 'transition-token',
            'token_hash' => hash('sha256', 'transition-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $incident = Incident::query()->create([
            'reference' => 'PREALARM-TRANSITION-001',
            'title' => 'Prealarm overgangstest',
            'priority' => 'normal',
            'status' => 'draft',
            'is_test' => false,
            'latitude' => 52.200000,
            'longitude' => 5.200000,
            'team_id' => $team->id,
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
            'created_by_email' => $actor->email,
            'opened_at' => now(),
        ]);
        $incident->teams()->attach($team->id, ['created_at' => now()]);

        $service = app(IncidentService::class);
        $service->update($incident, [
            'status' => 'active',
            'status_reason' => 'Vooraankondiging verstuurd.',
        ], $actor);

        $dispatch = DispatchRequest::query()->where('incident_id', $incident->id)->sole();
        $this->assertSame('draft', $dispatch->status);
        $preannouncementOutbox = DispatchPushOutbox::query()
            ->where('dispatch_request_id', $dispatch->id)
            ->where('message_type', 'incident_preannouncement')
            ->sole();
        $this->assertSame('dispatch_update', $preannouncementOutbox->data['type'] ?? null);
        $this->assertSame('availability', $preannouncementOutbox->data['action_mode'] ?? null);
        $this->assertNotNull($preannouncementOutbox->queued_at);
        Queue::assertPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->messageType === 'incident_preannouncement'
                && ($job->data['type'] ?? null) === 'dispatch_update'
                && ($job->data['action_mode'] ?? null) === 'availability'
                && $job->dispatchPushOutboxId === (string) $preannouncementOutbox->id,
        );
        $preannouncementJob = Queue::pushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->messageType === 'incident_preannouncement'
                && ($job->data['action_mode'] ?? null) === 'availability',
        )->sole();

        $availabilityResponse = app(DispatchService::class)->respond($dispatch, $pilot, 'accepted', null);
        $this->assertSame('accepted', $availabilityResponse->response_status);
        Queue::assertNotPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->messageType === 'dispatch_response_sync'
                && ($job->data['action_mode'] ?? null) === 'availability',
        );

        $service->update($incident->refresh(), [
            'status' => 'dispatching',
            'status_reason' => 'Alarmering verstuurd.',
        ], $actor);

        $dispatch->refresh();
        $outbox = DispatchPushOutbox::query()->where('message_type', 'dispatch_request')->sole();
        $this->assertSame('dispatching', $incident->refresh()->status);
        $this->assertSame('sent', $dispatch->status);
        $this->assertNotNull($dispatch->sent_at);
        $this->assertSame('queued_for_push', $dispatch->send_status);
        $this->assertNotNull($dispatch->send_queued_at);
        $this->assertNotNull($dispatch->send_released_at);
        $this->assertNotNull($preannouncementOutbox->refresh()->cancelled_at);
        $this->assertSame('superseded_by_alarm', $preannouncementOutbox->last_error_code);
        $this->assertSame($token->id, $outbox->fcm_token_id);
        $this->assertSame('dispatch_request', $outbox->message_type);
        $this->assertSame('dispatch_request', $outbox->data['type'] ?? null);
        $this->assertSame('attendance', $outbox->data['action_mode'] ?? null);
        $this->assertTrue($outbox->available_at->lessThanOrEqualTo(now()));
        $this->assertNotNull($outbox->queued_at);
        $this->assertDatabaseHas('dispatch_recipients', [
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pilot->id,
            'response_status' => 'pending',
        ]);
        $collapseId = 'dispatch-'.$dispatch->id;
        $this->assertSame($collapseId, PushNotificationIdentity::dispatchCollapseId([
            'type' => 'incident_preannouncement',
            'action_mode' => 'availability',
            'dispatch_id' => (string) $dispatch->id,
        ]));
        $this->assertSame($collapseId, PushNotificationIdentity::dispatchCollapseId([
            'type' => 'dispatch_response_sync',
            'action_mode' => 'availability',
            'dispatch_id' => (string) $dispatch->id,
        ]));
        $this->assertSame($collapseId, PushNotificationIdentity::dispatchCollapseId($outbox->data));
        $alarmJobs = Queue::pushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->messageType === 'dispatch_request'
                && ($job->data['action_mode'] ?? null) === 'attendance'
                && $job->dispatchPushOutboxId === (string) $outbox->id,
        );
        $this->assertCount(1, $alarmJobs);

        $provider = new class implements PushProvider
        {
            public int $sendCount = 0;

            public ?int $transactionLevel = null;

            /** @param array<string, string> $data */
            public function send(FcmToken $token, string $title, string $body, array $data = []): ClientResponse
            {
                $this->sendCount++;
                $this->transactionLevel = DB::transactionLevel();

                return new ClientResponse(new PsrResponse(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['name' => 'messages/preannouncement-transition'], JSON_THROW_ON_ERROR),
                ));
            }
        };
        $this->assertInstanceOf(SendFcmNotification::class, $preannouncementJob);
        $preannouncementJob->handle($provider, app(DispatchPushOutboxService::class));
        $this->assertSame(0, $provider->sendCount);
        $this->assertDatabaseMissing('push_delivery_logs', [
            'fcm_token_id' => $token->id,
            'dispatch_request_id' => $dispatch->id,
            'message_type' => 'incident_preannouncement',
        ]);

        $alarmJob = $alarmJobs->sole();
        $this->assertInstanceOf(SendFcmNotification::class, $alarmJob);
        $baselineTransactionLevel = DB::transactionLevel();
        $alarmJob->handle($provider, app(DispatchPushOutboxService::class));
        $this->assertSame(1, $provider->sendCount);
        $this->assertSame($baselineTransactionLevel, $provider->transactionLevel);
        $this->assertNotNull($outbox->refresh()->delivered_at);
        $this->assertDatabaseHas('push_delivery_logs', [
            'fcm_token_id' => $token->id,
            'dispatch_request_id' => $dispatch->id,
            'message_type' => 'dispatch_request',
            'status' => 'sent',
        ]);

        app(DispatchService::class)->respond($dispatch->refresh(), $pilot, 'accepted', null);
        Queue::assertPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->messageType === 'dispatch_response_sync'
                && ($job->data['action_mode'] ?? null) === 'attendance',
        );
    }

    public function test_alarm_provider_submission_waits_for_an_in_flight_dispatch_phase(): void
    {
        $actor = $this->user('ordered-alarm-actor@example.test', 'Ordered Actor');
        $pilot = $this->user('ordered-alarm-pilot@example.test', 'Ordered Pilot', pushEnabled: true);
        $token = FcmToken::query()->create([
            'user_id' => $pilot->id,
            'personal_access_token_id' => $this->operatorAccessTokenId($pilot, 'Ordered alarm device'),
            'device_id' => 'ordered-alarm-device',
            'token' => 'ordered-alarm-token',
            'token_hash' => hash('sha256', 'ordered-alarm-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $incident = Incident::query()->create([
            'reference' => 'ORDERED-ALARM-001',
            'title' => 'Provider ordering test',
            'priority' => 'normal',
            'status' => 'dispatching',
            'is_test' => false,
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
            'created_by_email' => $actor->email,
            'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
            'requested_by_email' => $actor->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Open de app.',
            'sent_at' => now(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'response_status' => 'pending',
        ]);
        $data = [
            'type' => 'dispatch_request',
            'action_mode' => 'attendance',
            'incident_id' => (string) $incident->id,
            'dispatch_id' => (string) $dispatch->id,
        ];
        $lockKey = PushNotificationIdentity::deliveryOrderLockKey(
            $data,
            (string) $token->id,
            (string) $dispatch->id,
        );
        $this->assertNotNull($lockKey);

        $root = storage_path('framework/testing/push-order-'.Str::uuid());
        $cachePath = $root.DIRECTORY_SEPARATOR.'cache';
        $readyPath = $root.DIRECTORY_SEPARATOR.'ready';
        $orderPath = $root.DIRECTORY_SEPARATOR.'order.log';
        $files = new Filesystem;
        $files->ensureDirectoryExists($cachePath);
        $originalCache = Cache::getFacadeRoot();
        $store = new FileStore($files, $cachePath);
        $store->setLockDirectory($cachePath);
        Cache::swap(new CacheRepository($store));

        $childCode = sprintf(
            <<<'PHP'
require %s;
$files = new Illuminate\Filesystem\Filesystem;
$store = new Illuminate\Cache\FileStore($files, %s);
$store->setLockDirectory(%s);
$cache = new Illuminate\Cache\Repository($store);
$cache->lock(%s, 5)->block(2, function (): void {
    file_put_contents(%s, "preannouncement_started\n", FILE_APPEND | LOCK_EX);
    touch(%s);
    usleep(750000);
    file_put_contents(%s, "preannouncement_finished\n", FILE_APPEND | LOCK_EX);
});
PHP,
            var_export(base_path('vendor/autoload.php'), true),
            var_export($cachePath, true),
            var_export($cachePath, true),
            var_export($lockKey, true),
            var_export($orderPath, true),
            var_export($readyPath, true),
            var_export($orderPath, true),
        );
        $lockHolder = new Process([PHP_BINARY, '-r', $childCode]);

        try {
            $lockHolder->start();
            $deadline = microtime(true) + 3;
            while (! is_file($readyPath) && microtime(true) < $deadline) {
                if (! $lockHolder->isRunning()) {
                    break;
                }
                usleep(20_000);
            }
            $this->assertFileExists($readyPath, $lockHolder->getErrorOutput());

            $provider = new class($orderPath) implements PushProvider
            {
                public function __construct(private readonly string $orderPath) {}

                /** @param array<string, string> $data */
                public function send(FcmToken $token, string $title, string $body, array $data = []): ClientResponse
                {
                    file_put_contents($this->orderPath, "alarm\n", FILE_APPEND | LOCK_EX);

                    return new ClientResponse(new PsrResponse(200));
                }
            };
            $job = new SendFcmNotification(
                (string) $token->id,
                'dispatch_request',
                'Alarmering',
                'Open de app.',
                $data,
                (string) $dispatch->id,
            );

            $job->handle($provider, app(DispatchPushOutboxService::class));
            $lockHolder->wait();

            $this->assertTrue($lockHolder->isSuccessful(), $lockHolder->getErrorOutput());
            $this->assertSame([
                'preannouncement_started',
                'preannouncement_finished',
                'alarm',
            ], file($orderPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        } finally {
            if ($lockHolder->isRunning()) {
                $lockHolder->stop();
            }
            Cache::swap($originalCache);
            $files->deleteDirectory($root);
        }
    }

    private function user(string $email, string $name, bool $pushEnabled = false): User
    {
        return User::query()->create([
            'name' => $name,
            'first_name' => str($name)->before(' ')->toString(),
            'last_name' => str($name)->after(' ')->toString(),
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => $pushEnabled,
        ]);
    }

    private function operatorAccessTokenId(User $user, string $name): string
    {
        return (string) $user->createToken(
            $name,
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken->getKey();
    }
}
