<?php

namespace Tests\Feature;

use App\Models\MobilePairingCode;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MobileAdminRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pairing_creation_is_retired_while_operator_pairing_remains_available(): void
    {
        $user = $this->user();
        $webToken = $this->token($user, 'client:web');

        foreach (['admin', 'admin_android', 'admin_ios'] as $clientType) {
            Auth::forgetGuards();
            $this->withToken($webToken)
                ->postJson('/api/auth/mobile-pairing', ['client_type' => $clientType])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'mobile_admin_retired')
                ->assertJsonPath('error.message', 'Beheer is alleen beschikbaar via de beveiligde webapp.');
        }

        Auth::forgetGuards();
        $pairing = $this->withToken($webToken)
            ->postJson('/api/auth/mobile-pairing', ['client_type' => 'operator_android'])
            ->assertCreated()
            ->assertJsonPath('data.client_type', 'operator');

        Auth::forgetGuards();
        $response = $this->withoutToken()
            ->postJson('/api/auth/mobile-pairing/consume', [
                'code' => $pairing->json('data.code'),
                'client_type' => 'operator_android',
                'device_name' => 'Operator Android',
            ])
            ->assertOk()
            ->assertJsonPath('data.client_type', 'operator_android');

        $this->assertIsString($response->json('data.token'));
        $this->assertNotSame('', $response->json('data.token'));
    }

    public function test_existing_admin_pairing_code_cannot_be_consumed_by_any_admin_client(): void
    {
        $user = $this->user();
        $pairing = MobilePairingCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', 'ADMIN23456'),
            'client_type' => 'admin',
            'expires_at' => now()->addMinute(),
        ]);

        foreach (['admin', 'admin_android', 'admin_ios'] as $clientType) {
            Auth::forgetGuards();
            $this->withoutToken()
                ->postJson('/api/auth/mobile-pairing/consume', [
                    'code' => 'ADMIN-23456',
                    'client_type' => $clientType,
                    'device_name' => 'Bestaande Admin-installatie',
                ])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'mobile_admin_retired')
                ->assertJsonPath('error.message', 'Beheer is alleen beschikbaar via de beveiligde webapp.');
        }

        $this->assertNull($pairing->refresh()->consumed_at);
    }

    public function test_legacy_admin_token_is_denied_while_operator_and_web_tokens_remain_valid(): void
    {
        $user = $this->user();

        Auth::forgetGuards();
        $this->withToken($this->token($user, 'client:admin'))
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mobile_admin_retired')
            ->assertJsonPath('error.message', 'Beheer is alleen beschikbaar via de beveiligde webapp.');

        Auth::forgetGuards();
        $this->withToken($this->token($user, 'client:operator'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        Auth::forgetGuards();
        $this->withToken($this->token($user, 'client:web'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_native_admin_password_login_is_retired_before_any_token_is_issued(): void
    {
        $user = $this->user();

        foreach (['admin_android', 'admin_ios'] as $clientType) {
            Auth::forgetGuards();
            $this->withoutToken()
                ->postJson('/api/auth/login', [
                    'email' => $user->email,
                    'password' => 'Test-password-123!',
                    'device_name' => 'Verouderde Admin-app',
                    'client_type' => $clientType,
                ])
                ->assertForbidden()
                ->assertJsonPath('error.code', 'mobile_admin_retired')
                ->assertJsonPath('error.message', 'Beheer is alleen beschikbaar via de beveiligde webapp.');
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function user(): User
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'app.public_url'],
            ['value' => 'https://dis.example.test', 'is_sensitive' => false],
        );
        $user = User::query()->create([
            'name' => 'Mobile retirement test',
            'first_name' => 'Mobile',
            'last_name' => 'Retirement',
            'email' => 'mobile-retirement-'.str()->lower((string) str()->ulid()).'@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'mobile-retirement-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Mobile retirement test',
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function token(User $user, string $clientAbility): string
    {
        return $user->createToken(
            'Mobile retirement test',
            ['*', $clientAbility],
            now()->addHour(),
        )->plainTextToken;
    }
}
