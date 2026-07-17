<?php

namespace Tests\Feature;

use App\Contracts\PushProvider;
use App\Jobs\SendFcmNotification;
use App\Models\DispatchPushOutbox;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PreannouncementAlarmTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_alarm_after_preannouncement_cannot_be_cancelled_by_delayed_availability_sync(): void
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
        Queue::assertPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->messageType === 'dispatch_update'
                && ($job->data['action_mode'] ?? null) === 'availability'
                && $job->dispatchPushOutboxId === null,
        );

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
        $outbox = DispatchPushOutbox::query()->sole();
        $this->assertSame('dispatching', $incident->refresh()->status);
        $this->assertSame('sent', $dispatch->status);
        $this->assertNotNull($dispatch->sent_at);
        $this->assertSame($token->id, $outbox->fcm_token_id);
        $this->assertSame('dispatch_request', $outbox->message_type);
        $this->assertSame('dispatch_request', $outbox->data['type'] ?? null);
        $this->assertSame('attendance', $outbox->data['action_mode'] ?? null);
        $this->assertNotNull($outbox->queued_at);
        $this->assertDatabaseHas('dispatch_recipients', [
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pilot->id,
            'response_status' => 'pending',
        ]);
        $collapseId = 'dispatch-'.$dispatch->id;
        $this->assertSame($collapseId, PushNotificationIdentity::dispatchCollapseId([
            'type' => 'dispatch_update',
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

            /** @param array<string, string> $data */
            public function send(FcmToken $token, string $title, string $body, array $data = []): ClientResponse
            {
                $this->sendCount++;

                return new ClientResponse(new PsrResponse(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['name' => 'messages/preannouncement-transition'], JSON_THROW_ON_ERROR),
                ));
            }
        };
        $alarmJob = $alarmJobs->sole();
        $this->assertInstanceOf(SendFcmNotification::class, $alarmJob);
        $alarmJob->handle($provider, app(DispatchPushOutboxService::class));
        $this->assertSame(1, $provider->sendCount);
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
}
