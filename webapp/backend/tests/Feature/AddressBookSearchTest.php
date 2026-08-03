<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AddressBookSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_is_case_insensitive_for_lower_upper_and_mixed_case_input(): void
    {
        $actor = $this->user('Adresboek Beheerder', 'address-book-actor@example.test');
        $this->grantAddressBookAccess($actor);
        $contact = $this->user('Marieke van Dijk', 'address-book-contact@example.test', [
            'first_name' => 'Marieke',
            'last_name' => 'van Dijk',
            'phone_number' => '+31612345678',
            'home_city' => 'Rotterdam',
            'home_region' => 'Zuid-Holland',
            'home_country' => 'NL',
        ]);
        $this->user('Andere Gebruiker', 'address-book-other@example.test');

        $this->asWebClient($actor);

        foreach (['marieke van dijk', 'MARIEKE VAN DIJK', 'mArIeKe VaN dIjK'] as $query) {
            $this->getJson('/api/address-book?q='.rawurlencode($query))
                ->assertOk()
                ->assertExactJson([
                    'data' => [[
                        'id' => $contact->id,
                        'name' => 'Marieke van Dijk',
                        'phone_number' => '+31612345678',
                        'city' => 'Rotterdam',
                        'region' => 'Zuid-Holland',
                        'country' => 'NL',
                    ]],
                ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $name, string $email, array $attributes = []): User
    {
        return User::query()->create($attributes + [
            'name' => $name,
            'first_name' => $name,
            'last_name' => $name,
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function grantAddressBookAccess(User $user): void
    {
        $permission = Permission::query()->where('name', 'address-book.view')->sole();
        $role = Role::query()->create([
            'name' => 'address-book-test-role',
            'display_name' => 'Adresboek testrol',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $role->permissions()->attach($permission->id, ['created_at' => now()]);
        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function asWebClient(User $user): void
    {
        $token = $user->createToken('Address book API test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
