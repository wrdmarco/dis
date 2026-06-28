<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PasswordPolicy;
use App\Services\TwoFactorService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

final class RegistrationController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly TwoFactorService $twoFactorService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'token' => ['required', 'string'],
        ]);

        $user = $this->userForToken((string) $data['email'], (string) $data['token']);
        if ($user === null) {
            return ApiResponse::error('invalid_invitation', 'Registratielink is ongeldig of verlopen.', 422);
        }

        $user->load(['roles.permissions', 'teams']);

        return ApiResponse::success([
            'user' => MobileApiPayload::user($user),
            'requires_mfa' => $this->requiresMfa($user),
            'admin_app_allowed' => $this->adminAppAllowed($user),
            'download_url' => '/download',
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', $this->passwordPolicy->rule()],
        ]);

        $user = $this->userForToken((string) $data['email'], (string) $data['token']);
        if ($user === null) {
            return ApiResponse::error('invalid_invitation', 'Registratielink is ongeldig of verlopen.', 422);
        }

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        $user->forceFill([
            'password' => Hash::make((string) $data['password']),
            'account_status' => 'active',
        ])->save();

        $user->load(['roles.permissions', 'teams']);
        $requiresMfa = $this->requiresMfa($user);
        $twoFactorSetup = null;
        $abilities = ['*'];

        if ($requiresMfa && ! $user->two_factor_enabled) {
            $secret = $this->twoFactorService->generateSecret();
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => null,
            ])->save();
            $twoFactorSetup = [
                'enabled' => false,
                'secret' => $secret,
                'provisioning_uri' => $this->twoFactorService->provisioningUri($user, $secret),
            ];
            $abilities = ['registration:2fa'];
        } else {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        $this->auditService->record('users.registration_completed', $user, $user, ['requires_mfa' => $requiresMfa]);

        $expiresAt = $requiresMfa ? now()->addMinutes(30) : null;

        return ApiResponse::success([
            'token' => $user->createToken('DIS Registration Wizard', $abilities, $expiresAt)->plainTextToken,
            'user' => MobileApiPayload::user($user->refresh()->load(['roles.permissions', 'teams'])),
            'requires_mfa' => $requiresMfa,
            'two_factor_setup' => $twoFactorSetup,
            'admin_app_allowed' => $this->adminAppAllowed($user),
            'download_url' => '/download',
        ]);
    }

    private function userForToken(string $email, string $token): ?User
    {
        $user = User::query()->with(['roles.permissions', 'teams'])->where('email', $email)->first();

        if ($user === null || ! Password::broker()->tokenExists($user, $token)) {
            return null;
        }

        return $user;
    }

    private function requiresMfa(User $user): bool
    {
        return $user->roles->contains(fn ($role): bool => (bool) $role->requires_two_factor);
    }

    private function adminAppAllowed(User $user): bool
    {
        return $user->canUseAdminApp();
    }
}
