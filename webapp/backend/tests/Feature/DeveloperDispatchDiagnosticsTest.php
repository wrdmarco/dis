<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\PushDeliveryLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DeveloperAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class DeveloperDispatchDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const DEVELOPER_KEY = 'dispatch-diagnostics-test-key';

    public function test_authentication_happens_before_identifier_validation(): void
    {
        $this->enableDeveloperAccess([DeveloperAccessService::SCOPE_LOGS_READ]);

        $this->withHeader('X-DIS-Developer-Key', 'incorrect-key')
            ->getJson('/api/developer/dispatches/not-an-ulid/diagnostics')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'developer_api_invalid_key');
    }

    public function test_logs_read_scope_and_valid_ulid_are_required(): void
    {
        $this->enableDeveloperAccess([DeveloperAccessService::SCOPE_ANDROID_UPLOAD]);
        $this->developerRequest((string) Str::ulid())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'developer_api_scope_denied');

        $this->enableDeveloperAccess([DeveloperAccessService::SCOPE_LOGS_READ]);
        $this->developerRequest('not-an-ulid')->assertUnprocessable();
    }

    public function test_unknown_dispatch_returns_a_consistent_not_found_response(): void
    {
        $this->enableDeveloperAccess([DeveloperAccessService::SCOPE_LOGS_READ]);

        $this->developerRequest((string) Str::ulid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'dispatch_not_found');
    }

    public function test_incident_lookup_is_authenticated_before_validation(): void
    {
        $this->enableDeveloperAccess([DeveloperAccessService::SCOPE_LOGS_READ]);

        $this->withHeader('X-DIS-Developer-Key', 'incorrect-key')
            ->getJson('/api/developer/incidents/not-an-ulid/dispatches')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'developer_api_invalid_key');
    }

    public function test_incident_lookup_exposes_only_safe_dispatch_identifiers_and_state(): void
    {
        $this->enableDeveloperAccess([DeveloperAccessService::SCOPE_LOGS_READ]);
        [$dispatch] = $this->dispatchFixture();

        $response = $this->developerIncidentRequest((string) $dispatch->incident_id)
            ->assertOk()
            ->assertJsonPath('data.incident_id', (string) $dispatch->incident_id)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.rows_truncated', false)
            ->assertJsonPath('data.dispatches.0.id', (string) $dispatch->id)
            ->assertJsonPath('data.dispatches.0.status', 'sent');

        $serialized = $response->getContent();
        foreach ([
            'Sensitive Requester Name',
            'sensitive-requester@example.test',
            'Sensitive incident title',
            'Sensitive dispatch message',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'developer.incident_dispatch_index_read',
            'target_type' => Incident::class,
            'target_id' => $dispatch->incident_id,
        ]);
    }

    public function test_diagnostics_expose_only_safe_aggregates_and_delivery_state(): void
    {
        $this->enableDeveloperAccess([DeveloperAccessService::SCOPE_LOGS_READ]);
        [$dispatch, $token, $user] = $this->dispatchFixture();

        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $user->id,
            'user_name' => 'Sensitive Recipient Name',
            'user_email' => 'sensitive-recipient@example.test',
            'response_status' => 'accepted',
            'response_note' => 'Sensitive response note',
            'notified_at' => now()->subMinute(),
            'responded_at' => now(),
        ]);
        $outbox = DispatchPushOutbox::query()->create([
            'deduplication_key' => hash('sha256', 'diagnostic-outbox'),
            'dispatch_request_id' => $dispatch->id,
            'fcm_token_id' => $token->id,
            'message_type' => 'dispatch_request',
            'title' => 'Sensitive notification title',
            'body' => 'Sensitive notification body',
            'data' => [
                'type' => 'dispatch_request',
                'dispatch_id' => (string) $dispatch->id,
                'secret' => 'sensitive-payload-secret',
            ],
            'available_at' => now(),
            'attempts' => 1,
            'last_attempted_at' => now(),
            'last_error_code' => 'queue_unavailable',
        ]);
        $preannouncementDelivery = PushDeliveryLog::query()->create([
            'user_id' => $user->id,
            'fcm_token_id' => $token->id,
            'dispatch_request_id' => $dispatch->id,
            'message_type' => 'dispatch_update',
            'status' => 'sent',
            'provider_message_id' => 'sensitive-provider-message-id',
            'sent_at' => now()->subMinute(),
        ]);
        $preannouncementDelivery->forceFill(['created_at' => now()->subMinute()])->save();
        $alarmDelivery = PushDeliveryLog::query()->create([
            'user_id' => $user->id,
            'fcm_token_id' => $token->id,
            'dispatch_request_id' => $dispatch->id,
            'message_type' => 'dispatch_request',
            'status' => 'failed',
            'error_code' => 'SECRET_TOKEN_ABC',
            'sent_at' => now(),
        ]);
        $alarmDelivery->forceFill(['created_at' => now()])->save();
        AuditLog::query()->create([
            'action' => 'dispatch.created',
            'target_type' => DispatchRequest::class,
            'target_id' => $dispatch->id,
            'metadata' => ['secret' => 'sensitive-audit-secret'],
            'created_at' => now()->subMinutes(2),
        ]);
        AuditLog::query()->create([
            'action' => 'dispatch.sent',
            'target_type' => DispatchRequest::class,
            'target_id' => $dispatch->id,
            'created_at' => now()->subMinute(),
        ]);
        AuditLog::query()->create([
            'action' => 'incidents.preannouncement_sent',
            'target_type' => Incident::class,
            'target_id' => $dispatch->incident_id,
            'metadata' => [
                'dispatch_ids' => [(string) $dispatch->id],
                'recipient_users' => 1,
                'queued_tokens' => 1,
                'warnings' => ['Sensitive warning detail'],
            ],
            'created_at' => now()->subMinutes(3),
        ]);

        $response = $this->developerRequest((string) $dispatch->id)
            ->assertOk()
            ->assertJsonPath('data.dispatch.id', (string) $dispatch->id)
            ->assertJsonPath('data.dispatch.status', 'sent')
            ->assertJsonPath('data.incident.id', (string) $dispatch->incident_id)
            ->assertJsonPath('data.recipients.total', 1)
            ->assertJsonPath('data.recipients.status_counts.accepted', 1)
            ->assertJsonPath('data.recipients.notified', 1)
            ->assertJsonPath('data.recipients.responded', 1)
            ->assertJsonPath('data.outbox.total', 1)
            ->assertJsonPath('data.outbox.state_counts.pending', 1)
            ->assertJsonPath('data.outbox.rows.0.id', (string) $outbox->id)
            ->assertJsonPath('data.outbox.rows.0.last_error_code', 'queue_unavailable')
            ->assertJsonPath('data.deliveries.total', 2)
            ->assertJsonPath('data.deliveries.status_counts.failed', 1)
            ->assertJsonPath('data.deliveries.status_counts.sent', 1)
            ->assertJsonPath('data.deliveries.message_type_counts.dispatch_request', 1)
            ->assertJsonPath('data.deliveries.message_type_counts.dispatch_update', 1)
            ->assertJsonPath('data.deliveries.rows.1.error_code', 'delivery_error')
            ->assertJsonPath('data.timeline.0.action', 'incidents.preannouncement_sent')
            ->assertJsonPath('data.timeline.0.target', 'incident')
            ->assertJsonPath('data.timeline.0.counts.queued_tokens', 1)
            ->assertJsonPath('data.timeline.1.action', 'dispatch.created')
            ->assertJsonPath('data.timeline.2.action', 'dispatch.sent');

        $serialized = $response->getContent();
        foreach ([
            'Sensitive Requester Name',
            'sensitive-requester@example.test',
            'Sensitive Recipient Name',
            'sensitive-recipient@example.test',
            'Sensitive response note',
            'sensitive-device-token',
            'sensitive-provider-message-id',
            'Sensitive notification title',
            'Sensitive notification body',
            'sensitive-payload-secret',
            'SECRET_TOKEN_ABC',
            'sensitive-audit-secret',
            'Sensitive warning detail',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'developer.dispatch_diagnostics_read',
            'target_type' => DispatchRequest::class,
            'target_id' => $dispatch->id,
        ]);
    }

    public function test_delivery_details_are_bounded_while_totals_remain_complete(): void
    {
        $this->enableDeveloperAccess([DeveloperAccessService::SCOPE_LOGS_READ]);
        [$dispatch, $token, $user] = $this->dispatchFixture();
        $now = now();

        for ($index = 0; $index < 251; $index++) {
            $delivery = PushDeliveryLog::query()->create([
                'user_id' => $user->id,
                'fcm_token_id' => $token->id,
                'dispatch_request_id' => $dispatch->id,
                'message_type' => 'dispatch_request',
                'status' => 'failed',
                'error_code' => $index === 250 ? 'UNREGISTERED' : 'delivery_exception',
                'sent_at' => $now->copy()->addSeconds($index),
            ]);
            $delivery->forceFill([
                'created_at' => $now->copy()->addSeconds($index),
                'updated_at' => $now->copy()->addSeconds($index),
            ])->save();
        }

        $response = $this->developerRequest((string) $dispatch->id)
            ->assertOk()
            ->assertJsonPath('data.deliveries.total', 251)
            ->assertJsonPath('data.deliveries.status_counts.failed', 251)
            ->assertJsonPath('data.deliveries.rows_truncated', true)
            ->assertJsonPath('data.deliveries.rows.249.error_code', 'UNREGISTERED');

        $this->assertCount(250, $response->json('data.deliveries.rows'));
    }

    /** @param list<string> $scopes */
    private function enableDeveloperAccess(array $scopes): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'developer.android_upload'],
            [
                'value' => [
                    'enabled' => true,
                    'key_hash' => hash('sha256', self::DEVELOPER_KEY),
                    'allowed_ips' => [],
                    'scopes' => $scopes,
                    'expires_at' => now()->addHour()->toAtomString(),
                ],
                'is_sensitive' => true,
            ],
        );
    }

    private function developerRequest(string $dispatchId): TestResponse
    {
        return $this->withHeader('X-DIS-Developer-Key', self::DEVELOPER_KEY)
            ->getJson("/api/developer/dispatches/{$dispatchId}/diagnostics");
    }

    private function developerIncidentRequest(string $incidentId): TestResponse
    {
        return $this->withHeader('X-DIS-Developer-Key', self::DEVELOPER_KEY)
            ->getJson("/api/developer/incidents/{$incidentId}/dispatches");
    }

    /** @return array{DispatchRequest, FcmToken, User} */
    private function dispatchFixture(): array
    {
        $user = User::query()->create([
            'name' => 'Sensitive Requester Name',
            'first_name' => 'Sensitive',
            'last_name' => 'Requester',
            'email' => 'sensitive-requester@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
        ]);
        $token = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'sensitive-device-id',
            'token' => 'sensitive-device-token',
            'token_hash' => hash('sha256', 'sensitive-device-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $incident = Incident::query()->create([
            'reference' => 'SENSITIVE-REFERENCE',
            'title' => 'Sensitive incident title',
            'description' => 'Sensitive incident description',
            'priority' => 'normal',
            'status' => 'dispatching',
            'is_test' => false,
            'location_label' => 'Sensitive incident location',
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
            'message' => 'Sensitive dispatch message',
            'sent_at' => now(),
        ]);

        return [$dispatch, $token, $user];
    }
}
