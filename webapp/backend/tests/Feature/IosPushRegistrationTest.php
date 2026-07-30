<?php

namespace Tests\Feature;

use App\Models\FcmToken;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class IosPushRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_operator_ios_device_can_register_its_apns_token(): void
    {
        $operator = User::query()->create([
            'name' => 'iOS Push Operator',
            'first_name' => 'iOS',
            'last_name' => 'Push Operator',
            'email' => 'ios-push-operator@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => false,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'ios-push-operator',
            'display_name' => 'iOS push operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $operator->roles()->attach($role->id, ['created_at' => now()]);

        $access = $operator->createToken(
            'DIS iOS integration test',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        Auth::forgetGuards();

        $this->withToken($access->plainTextToken)
            ->postJson('/api/devices/fcm-token', [
                'device_id' => 'ios-test-device',
                'token' => 'ios-test-apns-token',
                'platform' => 'ios',
                'client_type' => 'operator',
                'device_type' => 'phone',
                'device_name' => 'Test iPhone',
                'device_manufacturer' => 'Apple',
                'device_model' => 'iPhone',
                'app_version' => '0.1.280',
            ])
            ->assertNoContent();

        $registeredDevice = FcmToken::query()->sole();

        $this->assertSame((string) $operator->id, (string) $registeredDevice->user_id);
        $this->assertSame('ios-test-device', $registeredDevice->device_id);
        $this->assertSame('ios-test-apns-token', $registeredDevice->token);
        $this->assertSame(hash('sha256', 'ios-test-apns-token'), $registeredDevice->token_hash);
        $this->assertSame('ios', $registeredDevice->platform);
        $this->assertSame('operator', $registeredDevice->client_type);
        $this->assertSame((string) $access->accessToken->id, (string) $registeredDevice->personal_access_token_id);
        $this->assertTrue($registeredDevice->is_active);
        $this->assertNotNull($registeredDevice->last_seen_at);
        $this->assertTrue($operator->refresh()->push_enabled);
    }
}
