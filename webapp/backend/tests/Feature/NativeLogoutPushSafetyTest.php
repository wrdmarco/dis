<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use App\Models\Role;
use App\Models\User;
use App\Services\FcmTokenIdentityLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NativeLogoutPushSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_logout_revokes_only_devices_bound_to_the_current_session(): void
    {
        Queue::fake();
        $user = $this->operator('current-session-only');
        $current = $user->createToken(
            'Current operator session',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $other = $user->createToken(
            'Other operator session',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $currentPhone = $this->token(
            $user,
            'current-phone',
            'current-phone-provider',
            (string) $current->accessToken->id,
        );
        $currentTablet = $this->token(
            $user,
            'current-tablet',
            'current-tablet-provider',
            (string) $current->accessToken->id,
        );
        $otherPhone = $this->token(
            $user,
            'other-phone',
            'other-phone-provider',
            (string) $other->accessToken->id,
        );
        $this->available($user);

        $this->withToken($current->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $current->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $other->accessToken->id,
        ]);
        foreach ([$currentPhone, $currentTablet] as $revokedToken) {
            $this->assertFalse($revokedToken->refresh()->is_active);
            $this->assertNotNull($revokedToken->revoked_at);
            $this->assertNotSame('', (string) $revokedToken->revocation_generation);
        }
        $this->assertTrue($otherPhone->refresh()->is_active);
        $this->assertTrue($user->refresh()->push_enabled);
        $this->assertSame(1, DB::table('availability_statuses')
            ->where('user_id', $user->id)
            ->count());
        Queue::assertPushed(SendFcmNotification::class, 2);
    }

    public function test_last_operator_session_logout_forces_unavailable_but_preserves_other_client_sessions(): void
    {
        Queue::fake();
        $user = $this->operator('last-operator-session');
        $operator = $user->createToken(
            'Operator session',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $otherClient = $user->createToken(
            'Other client session',
            ['*', 'client:admin'],
            now()->addHour(),
        );
        $operatorToken = $this->token(
            $user,
            'last-operator-device',
            'last-operator-provider',
            (string) $operator->accessToken->id,
        );
        $otherClientToken = $this->token(
            $user,
            'other-client-device',
            'other-client-provider',
            (string) $otherClient->accessToken->id,
            'admin',
        );
        $this->available($user);

        $this->withToken($operator->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->assertFalse($operatorToken->refresh()->is_active);
        $this->assertTrue($otherClientToken->refresh()->is_active);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherClient->accessToken->id,
        ]);
        $this->assertFalse($user->refresh()->push_enabled);
        $this->assertFalse((bool) DB::table('availability_statuses')
            ->where('user_id', $user->id)
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('is_available'));
        Queue::assertPushed(SendFcmNotification::class, 1);
    }

    public function test_native_logout_cannot_cross_an_in_flight_user_state_transition(): void
    {
        Queue::fake();
        $identityLock = new FcmTokenIdentityLock(FcmTokenIdentityLock::LOCK_SECONDS, 0);
        $this->app->instance(FcmTokenIdentityLock::class, $identityLock);
        $user = $this->operator('logout-user-lock');
        $access = $user->createToken(
            'Logout race session',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $token = $this->token(
            $user,
            'logout-race-device',
            'logout-race-provider',
            (string) $access->accessToken->id,
        );
        $heldLock = Cache::lock(
            FcmTokenIdentityLock::userKey((string) $user->id),
            FcmTokenIdentityLock::LOCK_SECONDS,
        );
        $this->assertTrue($heldLock->get());

        try {
            $this->withToken($access->plainTextToken)
                ->postJson('/api/auth/logout')
                ->assertStatus(503)
                ->assertHeader('Retry-After', '2')
                ->assertJsonPath('error.code', 'push_device_state_busy');
            $this->assertDatabaseHas('personal_access_tokens', [
                'id' => $access->accessToken->id,
            ]);
            $this->assertTrue($token->refresh()->is_active);
        } finally {
            $heldLock->release();
        }

        $this->withToken($access->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $access->accessToken->id,
        ]);
        $this->assertFalse($token->refresh()->is_active);
    }

    public function test_operator_logout_before_device_registration_repairs_stale_push_state(): void
    {
        Queue::fake();
        $user = $this->operator('logout-before-registration');
        $current = $user->createToken(
            'Unregistered operator session',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $other = $user->createToken(
            'Other unregistered operator session',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $this->available($user);

        $this->withToken($current->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $current->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $other->accessToken->id,
        ]);
        $this->assertDatabaseMissing('fcm_tokens', [
            'user_id' => $user->id,
            'is_active' => true,
            'client_type' => 'operator',
        ]);
        $this->assertFalse($user->refresh()->push_enabled);
        $this->assertFalse((bool) DB::table('availability_statuses')
            ->where('user_id', $user->id)
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('is_available'));
        Queue::assertNothingPushed();
    }

    private function operator(string $suffix): User
    {
        $user = User::query()->create([
            'name' => 'Native Logout '.$suffix,
            'first_name' => 'Native',
            'last_name' => 'Logout '.$suffix,
            'email' => "native-logout-{$suffix}@example.test",
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'native-logout-'.$suffix,
            'display_name' => 'Native logout '.$suffix,
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function token(
        User $user,
        string $deviceId,
        string $providerToken,
        string $personalAccessTokenId,
        string $clientType = 'operator',
    ): FcmToken {
        return FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'personal_access_token_id' => $personalAccessTokenId,
            'platform' => $clientType === 'operator' ? 'android' : 'ios',
            'client_type' => $clientType,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }

    private function available(User $user): void
    {
        DB::table('availability_statuses')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => 'available',
            'is_available' => true,
            'is_system_applied' => false,
            'effective_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }
}
