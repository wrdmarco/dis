<?php

namespace Tests\Feature;

use App\Models\Certification;
use App\Models\MobilePairingCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CertificationAccessCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_paired_operator_can_register_push_and_load_the_complete_mobile_bootstrap(): void
    {
        $this->certificationWithOwner();
        $operator = $this->user('paired-operator@example.test');
        $this->grant($operator, ['incidents.assigned.view'], operator: true, admin: false);
        $pairingCode = 'ABCDE-23456';

        MobilePairingCode::query()->create([
            'user_id' => $operator->id,
            'code_hash' => hash('sha256', 'ABCDE23456'),
            'client_type' => 'operator',
            'expires_at' => now()->addMinute(),
        ]);

        $pairing = $this->postJson('/api/auth/mobile-pairing/consume', [
            'code' => $pairingCode,
            'client_type' => 'operator_android',
            'device_name' => 'DIS Android integration test',
        ])->assertOk();

        $token = $pairing->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson('/api/devices/fcm-token', [
                'device_id' => 'paired-operator-device',
                'token' => 'paired-operator-fcm-token',
                'platform' => 'android',
                'client_type' => 'operator',
                'device_type' => 'phone',
                'device_name' => 'Integration test phone',
                'app_version' => 'test',
            ])
            ->assertNoContent();

        $this->assertTrue($operator->refresh()->push_enabled);

        foreach ([
            '/api/auth/me',
            '/api/status/me',
            '/api/availability-schedule/me',
            '/api/calendar-events',
            '/api/incidents?active_alarms=true',
            '/api/pilot-report/form-config',
            '/api/assets/mine',
            '/api/drone-types',
            '/api/certifications',
            '/api/certifications/options',
            '/api/certifications/me',
        ] as $endpoint) {
            Auth::forgetGuards();
            $response = $this->withToken($token)->getJson($endpoint);

            $this->assertSame(200, $response->status(), $endpoint.' returned '.$response->getContent());
        }
    }

    public function test_operator_pilot_can_load_certification_summary_without_other_users_details(): void
    {
        [$certification, $certificationOwner] = $this->certificationWithOwner();
        $operator = $this->user('operator-pilot@example.test');
        $this->grant($operator, ['incidents.assigned.view'], operator: true, admin: false);

        $response = $this->asMobileClient($operator, 'client:operator')
            ->getJson('/api/certifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $certification->id)
            ->assertJsonPath('data.0.code', 'CERT-COMPAT')
            ->assertJsonMissingPath('data.0.user_certifications');

        $this->assertStringNotContainsString($certificationOwner->id, $response->getContent());
        $this->assertStringNotContainsString($certificationOwner->email, $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-CERTIFICATE-NUMBER', $response->getContent());
    }

    public function test_certification_options_never_include_user_certifications_or_identity_data(): void
    {
        [$certification, $certificationOwner] = $this->certificationWithOwner();
        $operator = $this->user('options-operator@example.test');
        $this->grant($operator, [], operator: true, admin: false);

        $response = $this->asMobileClient($operator, 'client:operator')
            ->getJson('/api/certifications/options')
            ->assertOk()
            ->assertJsonPath('data.0.id', $certification->id)
            ->assertJsonMissingPath('data.0.user_certifications');

        $this->assertStringNotContainsString($certificationOwner->id, $response->getContent());
        $this->assertStringNotContainsString($certificationOwner->email, $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-CERTIFICATE-NUMBER', $response->getContent());
    }

    public function test_admin_with_certifications_view_keeps_the_full_management_payload(): void
    {
        [$certification, $certificationOwner] = $this->certificationWithOwner();
        $admin = $this->user('certification-admin@example.test');
        $this->grant($admin, ['certifications.view'], operator: false, admin: true);

        $this->asMobileClient($admin, 'client:admin')
            ->getJson('/api/certifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $certification->id)
            ->assertJsonPath('data.0.user_certifications.0.user_id', $certificationOwner->id)
            ->assertJsonPath('data.0.user_certifications.0.certificate_number', 'PRIVATE-CERTIFICATE-NUMBER')
            ->assertJsonPath('data.0.user_certifications.0.user.email', $certificationOwner->email);
    }

    public function test_admin_without_certifications_view_only_receives_the_summary(): void
    {
        [$certification, $certificationOwner] = $this->certificationWithOwner();
        $admin = $this->user('unprivileged-admin@example.test');
        $this->grant($admin, [], operator: false, admin: true);

        $response = $this->asMobileClient($admin, 'client:admin')
            ->getJson('/api/certifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $certification->id)
            ->assertJsonMissingPath('data.0.user_certifications');

        $this->assertStringNotContainsString($certificationOwner->email, $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-CERTIFICATE-NUMBER', $response->getContent());
    }

    public function test_certification_endpoints_still_require_authentication(): void
    {
        $this->getJson('/api/certifications')->assertUnauthorized();
        $this->getJson('/api/certifications/options')->assertUnauthorized();
    }

    /**
     * @return array{Certification, User}
     */
    private function certificationWithOwner(): array
    {
        $owner = $this->user('certification-owner-'.strtolower((string) str()->ulid()).'@example.test');
        $certification = Certification::query()->create([
            'code' => 'CERT-COMPAT',
            'name' => 'Compatibility certificate',
            'description' => 'Certificate type visible to operators.',
            'is_required_for_dispatch' => true,
            'warning_days_before_expiry' => 30,
        ]);
        UserCertification::query()->create([
            'user_id' => $owner->id,
            'certification_id' => $certification->id,
            'issued_at' => now()->subDay()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'certificate_number' => 'PRIVATE-CERTIFICATE-NUMBER',
            'status' => 'active',
        ]);

        return [$certification, $owner];
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Certification Test User',
            'first_name' => 'Certification',
            'last_name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function grant(User $user, array $permissionNames, bool $operator, bool $admin): void
    {
        $role = Role::query()->create([
            'name' => 'certification-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Certification test role',
            'can_use_operator_app' => $operator,
            'can_use_admin_app' => $admin,
        ]);
        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'category' => 'certification-test',
                    'display_name' => $permissionName,
                    'description' => 'Certification test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function asMobileClient(User $user, string $clientAbility): static
    {
        $token = $user->createToken('Certification compatibility client', ['*', $clientAbility], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
