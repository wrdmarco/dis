<?php

namespace Tests\Feature;

use App\Events\SystemUpdateStatusChanged;
use App\Http\Controllers\AdminController;
use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use App\Models\PushDeliveryLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DroneFlightContextService;
use App\Services\PushProviderClient;
use App\Services\SystemUpdateStatusService;
use App\Support\SensitiveDataRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class OperationalErrorSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_redactor_handles_environment_cli_uri_and_private_key_secrets(): void
    {
        $redacted = app(SensitiveDataRedactor::class)->redactString(implode("\n", [
            'DB_PASSWORD="database-secret" APP_KEY=base64:application-secret',
            'composer --token cli-secret --password=cli-password',
            'https://deploy-user:remote-secret@example.test/repository.git',
            "-----BEGIN PRIVATE KEY-----\nprivate-key-secret\n-----END PRIVATE KEY-----",
        ]));

        $this->assertStringContainsString('[REDACTED]', $redacted);
        foreach (['database-secret', 'application-secret', 'cli-secret', 'cli-password', 'deploy-user', 'remote-secret', 'private-key-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $redacted);
        }
    }

    public function test_drone_context_never_returns_transport_exception_details(): void
    {
        Exceptions::fake([RuntimeException::class]);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'drone.aeret_api_url'],
            ['value' => 'https://aeret.example.test/context', 'is_sensitive' => false],
        );
        Http::fake(static function (ClientRequest $request): never {
            throw new RuntimeException('SQLSTATE[08006] password=flight-secret at C:\\private\\FlightClient.php:42');
        });

        $context = app(DroneFlightContextService::class)->preview(52.1, 5.1, 'Testlocatie');
        $serialized = json_encode($context, JSON_THROW_ON_ERROR);

        $this->assertSame('Aeret/NOTAM gegevens konden niet worden opgehaald.', $context['airspace']['errors'][0]);
        $this->assertSame('Weerdata kon niet worden opgehaald.', $context['weather']['errors'][0]);
        foreach (['flight-secret', 'SQLSTATE', 'FlightClient.php', 'RuntimeException'] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }
    }

    public function test_push_delivery_logs_store_and_expose_only_stable_error_codes(): void
    {
        Exceptions::fake([RuntimeException::class]);
        $user = $this->user('push-errors@example.test');
        $token = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'push-error-device',
            'token' => 'fcm-device-secret',
            'token_hash' => hash('sha256', 'fcm-device-secret'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        Cache::put('firebase.messaging.access_token', 'firebase-access-secret', now()->addHour());
        Http::fake(static function (ClientRequest $request): never {
            throw new RuntimeException('Authorization: Bearer provider-secret at C:\\private\\FcmClient.php:91');
        });

        try {
            (new SendFcmNotification($token->id, 'security_test', 'Test', 'Test'))->handle(app(PushProviderClient::class));
            $this->fail('The simulated FCM transport exception was not thrown.');
        } catch (RuntimeException) {
            // The queue must still retry, while the persisted diagnostic remains non-sensitive.
        }

        $this->assertDatabaseHas('push_delivery_logs', [
            'fcm_token_id' => $token->id,
            'error_code' => 'delivery_exception',
        ]);

        Http::swap(new HttpFactory(app('events')));
        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'SQLSTATE[08006] provider-password=provider-body-secret'],
            ], 500),
        ]);
        (new SendFcmNotification($token->id, 'security_test', 'Test', 'Test'))->handle(app(PushProviderClient::class));
        $this->assertDatabaseHas('push_delivery_logs', [
            'fcm_token_id' => $token->id,
            'error_code' => 'fcm_http_500',
        ]);

        PushDeliveryLog::query()->create([
            'user_id' => $user->id,
            'fcm_token_id' => $token->id,
            'message_type' => 'legacy_security_test',
            'status' => 'failed',
            'error_code' => 'SQLSTATE[08006] password=historical-secret at /opt/dis/private.php:42',
            'sent_at' => now()->addSecond(),
        ]);
        $response = app(AdminController::class)->pushLogs(Request::create('/api/admin/push/logs', 'GET'));
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        $legacyLog = collect($payload['data'])->firstWhere('message_type', 'legacy_security_test');

        $this->assertIsArray($legacyLog);
        $this->assertSame('delivery_error', $legacyLog['error_code']);
        foreach (['provider-secret', 'provider-body-secret', 'historical-secret', 'SQLSTATE', 'private.php'] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }
    }

    public function test_mail_test_returns_a_generic_validation_error(): void
    {
        Exceptions::fake([RuntimeException::class]);
        $user = $this->user('mail-errors@example.test');
        $request = Request::create('/api/admin/mail/test', 'POST');
        $request->setUserResolver(static fn (): User => $user);
        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new RuntimeException('SMTP password=mail-secret at /opt/dis/vendor/Mailer.php:42'));

        try {
            app(AdminController::class)->testMail($request);
            $this->fail('The simulated mail transport exception was not converted to validation feedback.');
        } catch (ValidationException $exception) {
            $serialized = json_encode($exception->errors(), JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('Testmail verzenden mislukt. Controleer de mailinstellingen en probeer opnieuw.', $serialized);
            foreach (['mail-secret', 'Mailer.php', '/opt/dis', 'RuntimeException'] as $sensitiveValue) {
                $this->assertStringNotContainsString($sensitiveValue, $serialized);
            }
        }
    }

    public function test_public_update_status_redacts_secrets_paths_and_internal_state(): void
    {
        Cache::forget('system.update.status');
        Event::fake([SystemUpdateStatusChanged::class]);
        $status = app(SystemUpdateStatusService::class);
        $status->start('App-update gestart.');
        $status->append('DB_PASSWORD=database-secret --token cli-secret');
        $status->append('Fetch https://deploy-user:remote-secret@example.test/repository.git from /opt/dis/private');
        $status->append('SQLSTATE[08006] connection failed at /opt/dis/vendor/Connection.php:42');

        $public = $status->publicStatus();
        $serialized = json_encode($public, JSON_THROW_ON_ERROR);

        foreach (['database-secret', 'cli-secret', 'deploy-user', 'remote-secret', '/opt/dis', 'SQLSTATE', 'Connection.php'] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }
        foreach (['runner_pid', 'runner_unit', 'runner_log_offset', 'last_log_at'] as $internalKey) {
            $this->assertArrayNotHasKey($internalKey, $public);
        }
        $this->assertStringContainsString('[REDACTED]', $serialized);
        $this->assertStringContainsString('[PATH]', $serialized);
        $this->assertStringContainsString('Interne updatefout. Raadpleeg de beveiligde serverlogs.', $serialized);

        Event::assertDispatched(SystemUpdateStatusChanged::class, static function (SystemUpdateStatusChanged $event): bool {
            $serializedEvent = json_encode($event->status, JSON_THROW_ON_ERROR);

            return ! array_key_exists('runner_log_offset', $event->status)
                && ! str_contains($serializedEvent, 'database-secret');
        });
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Operational Security User',
            'first_name' => 'Operational',
            'last_name' => 'Security User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
        ]);
    }
}
