<?php

namespace Tests\Feature;

use App\Models\FcmToken;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuthenticationStateRevocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationStateRevocationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_revocation_invalidates_every_authentication_channel(): void
    {
        $user = User::query()->create([
            'name' => 'Restore Revocation User',
            'first_name' => 'Restore',
            'last_name' => 'Revocation User',
            'email' => 'restore-revocation@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
            'auth_session_version' => 4,
        ]);
        DB::table('users')->where('id', $user->id)->update(['auth_session_version' => 4]);
        $user->createToken('Restored token', ['*']);
        FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'restored-device',
            'token' => 'restored-fcm-token',
            'token_hash' => hash('sha256', 'restored-fcm-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'restored-session',
            'user_id' => $user->id,
            'ip_address' => '192.0.2.30',
            'user_agent' => 'Restore regression',
            'payload' => 'restored-session-payload',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('restored-password-reset-token'),
            'created_at' => now(),
        ]);
        DB::table('mobile_pairing_codes')->insert([
            'id' => (string) str()->ulid(),
            'user_id' => $user->id,
            'code_hash' => hash('sha256', 'restored-pairing-code'),
            'client_type' => 'operator',
            'review_mode' => null,
            'expires_at' => now()->addMinutes(5),
            'consumed_at' => null,
            'consumed_ip' => null,
            'consumed_user_agent' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SystemSetting::query()->create([
            'key' => 'developer.android_upload',
            'value' => [
                'enabled' => true,
                'key_hash' => hash('sha256', 'restored-developer-key'),
                'scopes' => ['system_update'],
            ],
            'is_sensitive' => true,
        ]);

        $counts = app(AuthenticationStateRevocationService::class)->revokeAll();

        $this->assertSame(1, $counts['tokens']);
        $this->assertSame(1, $counts['sessions']);
        $this->assertSame(1, $counts['password_reset_tokens']);
        $this->assertSame(1, $counts['pairing_codes']);
        $this->assertSame(1, $counts['developer_keys']);
        $this->assertSame(1, $counts['push_tokens']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('mobile_pairing_codes', 0);
        $this->assertDatabaseMissing('system_settings', ['key' => 'developer.android_upload']);
        $this->assertFalse(FcmToken::query()->firstOrFail()->is_active);
        $this->assertFalse($user->refresh()->push_enabled);
        $this->assertSame(5, $user->auth_session_version);
    }
}
