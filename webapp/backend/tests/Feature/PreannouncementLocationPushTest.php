<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PreannouncementLocationPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_preannouncement_push_uses_the_city_instead_of_postcode_letters(): void
    {
        Queue::fake();
        $actor = $this->user('coordinator@example.test');
        $recipient = $this->user('operator@example.test');
        $team = Team::query()->create([
            'code' => 'OCP-LOCATION-TEST',
            'name' => 'Locatietest',
            'type' => 'base',
            'is_operational' => true,
        ]);
        $incident = Incident::query()->create([
            'reference' => 'PRE-LOCATION-001',
            'title' => 'Locatietest',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => false,
            'location_label' => "McDonald's, Botnische golf 1, 3446 CN, Woerden, Utrecht, Nederland",
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
            'created_by_email' => $actor->email,
            'team_id' => $team->id,
            'opened_at' => now(),
        ]);
        $incident->teams()->attach($team->id, ['created_at' => now()]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
            'requested_by_email' => $actor->email,
            'target_team_id' => $team->id,
            'status' => 'draft',
            'priority' => 'normal',
            'message' => 'Test vooraankondiging',
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $recipient->id,
            'user_name' => $recipient->name,
            'user_email' => $recipient->email,
            'response_status' => 'pending',
        ]);
        $token = FcmToken::query()->create([
            'user_id' => $recipient->id,
            'device_id' => 'operator-location-device',
            'token' => 'operator-location-token',
            'token_hash' => hash('sha256', 'operator-location-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);

        $result = $this->app->make(DispatchService::class)
            ->sendPreannouncementForIncidentActivation($incident, $actor);

        $this->assertSame(1, $result['queued_tokens']);
        $outbox = DispatchPushOutbox::query()->sole();
        $this->assertSame('incident_preannouncement', $outbox->message_type);
        $this->assertSame('dispatch_update', $outbox->data['type'] ?? null);
        $this->assertSame('availability', $outbox->data['action_mode'] ?? null);
        $this->app->make(DispatchPushOutboxService::class)->flushPending(100, (string) $dispatch->id);
        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job) use ($dispatch, $token): bool {
            return $job->fcmTokenId === $token->id
                && $job->dispatchRequestId === $dispatch->id
                && $job->messageType === 'incident_preannouncement'
                && ($job->data['type'] ?? null) === 'dispatch_update'
                && ($job->data['action_mode'] ?? null) === 'availability'
                && $job->body === 'Ben je beschikbaar voor een melding in Woerden?'
                && ! str_contains($job->body, 'CN');
        });
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
