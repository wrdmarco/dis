<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DispatchPushOutboxService;
use App\Services\PushProviderClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AuthenticationStateCutoverMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_revokes_pre_hardening_authentication_state(): void
    {
        $user = User::query()->create([
            'name' => 'Authentication Cutover User',
            'first_name' => 'Authentication',
            'last_name' => 'Cutover User',
            'email' => 'authentication-cutover@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
        ]);

        $mobileToken = $user->createToken(
            'Pre-hardening mobile session',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;

        $fcmToken = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'pre-hardening-device',
            'token' => 'pre-hardening-fcm-token',
            'token_hash' => hash('sha256', 'pre-hardening-fcm-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $pendingMfaToken = $user->createToken(
            'Pre-hardening MFA challenge',
            ['2fa:pending', 'client:admin'],
            now()->addMinutes(5),
        )->accessToken;

        DB::table('sessions')->insert([
            [
                'id' => 'pre-hardening-authenticated-session',
                'user_id' => $user->id,
                'ip_address' => '192.0.2.10',
                'user_agent' => 'Migration regression test',
                'payload' => 'authenticated-session-payload',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'pre-hardening-anonymous-session',
                'user_id' => null,
                'ip_address' => '192.0.2.11',
                'user_agent' => 'Migration regression test',
                'payload' => 'anonymous-session-payload',
                'last_activity' => now()->timestamp,
            ],
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('pre-hardening-password-reset-token'),
            'created_at' => now(),
        ]);

        $unusedPairingId = (string) str()->ulid();
        $reusableStoreReviewPairingId = (string) str()->ulid();
        DB::table('mobile_pairing_codes')->insert([
            [
                'id' => $unusedPairingId,
                'user_id' => $user->id,
                'code_hash' => hash('sha256', 'unused-pre-hardening-pairing'),
                'client_type' => 'operator',
                'review_mode' => null,
                'expires_at' => now()->addMinutes(5),
                'consumed_at' => null,
                'consumed_ip' => null,
                'consumed_user_agent' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $reusableStoreReviewPairingId,
                'user_id' => $user->id,
                'code_hash' => hash('sha256', 'reusable-store-review-pairing'),
                'client_type' => 'admin',
                'review_mode' => 'store_android',
                'expires_at' => now()->addHours(5),
                'consumed_at' => now()->subMinutes(2),
                'consumed_ip' => '192.0.2.12',
                'consumed_user_agent' => 'Migration regression test',
                'created_at' => now()->subMinutes(3),
                'updated_at' => now()->subMinutes(2),
            ],
        ]);
        SystemSetting::query()->create([
            'key' => 'developer.android_upload',
            'value' => [
                'enabled' => true,
                'key_hash' => hash('sha256', 'pre-hardening-developer-key'),
                'scopes' => ['system_update'],
            ],
            'is_sensitive' => true,
        ]);

        $migration = require database_path('migrations/2026_07_14_000002_revoke_legacy_authentication_state.php');
        $migration->up();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $mobileToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $pendingMfaToken->id]);
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseMissing('mobile_pairing_codes', ['id' => $unusedPairingId]);
        $this->assertDatabaseMissing('mobile_pairing_codes', ['id' => $reusableStoreReviewPairingId]);
        $this->assertDatabaseMissing('system_settings', ['key' => 'developer.android_upload']);

        $fcmToken->refresh();
        $this->assertFalse($fcmToken->is_active);
        $this->assertNotNull($fcmToken->revoked_at);
        $this->assertTrue($fcmToken->revoked_at->equalTo($fcmToken->updated_at));
        $this->assertFalse($user->refresh()->push_enabled);

        Http::fake();
        (new SendFcmNotification(
            $fcmToken->id,
            'pre_hardening_queued_message',
            'Queued before cutover',
            'Must not be delivered after cutover',
        ))->handle(app(PushProviderClient::class), app(DispatchPushOutboxService::class));
        Http::assertNothingSent();
        $this->assertDatabaseMissing('push_delivery_logs', ['fcm_token_id' => $fcmToken->id]);
    }
}
