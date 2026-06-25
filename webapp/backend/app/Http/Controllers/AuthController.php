<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly TwoFactorService $twoFactorService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->with(['roles.permissions', 'teams'])->where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            $this->auditService->record('auth.login_failed', User::class, null, ['email' => $request->validated('email')], null, $request);
            throw ValidationException::withMessages(['email' => ['The provided credentials are invalid.']]);
        }

        if ($user->account_status !== 'active') {
            $this->auditService->record('auth.login_blocked', $user, $user, ['account_status' => $user->account_status], null, $request);
            return ApiResponse::error('forbidden', 'The account is not active.', 403);
        }

        $tokenName = $request->validated('device_name') ?? 'DIS API';
        $requiresTwoFactor = $user->roles->contains(fn ($role) => $role->requires_two_factor);
        if ($requiresTwoFactor && ! $user->two_factor_enabled) {
            $secret = $this->twoFactorService->generateSecret();
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => null,
            ])->save();
            $token = $user->createToken($tokenName, ['2fa:setup'], now()->addMinutes(30))->plainTextToken;
            $this->auditService->record('auth.2fa_setup_required', $user, $user, [], null, $request);

            return ApiResponse::success([
                'requires_2fa' => false,
                'requires_2fa_setup' => true,
                'token' => $token,
                'user' => MobileApiPayload::user($user),
                'two_factor_setup' => [
                    'enabled' => false,
                    'secret' => $secret,
                    'provisioning_uri' => $this->twoFactorService->provisioningUri($user, $secret),
                ],
            ], 202);
        }

        if ($requiresTwoFactor) {
            $token = $user->createToken($tokenName, ['2fa:pending'], now()->addMinutes(10))->plainTextToken;
            $this->auditService->record('auth.login_2fa_required', $user, $user, [], null, $request);

            return ApiResponse::success(['requires_2fa' => true, 'token' => $token], 202);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->auditService->record('auth.login_succeeded', $user, $user, [], null, $request);

        return ApiResponse::success([
            'requires_2fa' => false,
            'token' => $user->createToken($tokenName, ['*'])->plainTextToken,
            'user' => MobileApiPayload::user($user),
        ]);
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6'], 'device_name' => ['nullable', 'string', 'max:120']]);
        $user = $request->user();

        if ($user === null || ! $this->currentTokenHasExactAbility($request, '2fa:pending')) {
            return ApiResponse::error('forbidden', 'A pending two-factor token is required.', 403);
        }

        if (! $this->twoFactorService->verify($user, (string) $request->input('code'))) {
            $this->auditService->record('auth.2fa_failed', $user, $user, [], null, $request);
            return ApiResponse::error('invalid_two_factor_code', 'The two-factor code is invalid.', 422, [
                'code' => ['The two-factor code is invalid.'],
            ]);
        }

        $request->user()?->currentAccessToken()?->delete();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'last_login_at' => now()])->save();
        $this->auditService->record('auth.2fa_verified', $user, $user, [], null, $request);

        return ApiResponse::success([
            'token' => $user->createToken((string) ($request->input('device_name') ?? 'DIS API'), ['*'])->plainTextToken,
            'user' => MobileApiPayload::user($user->load(['roles', 'teams'])),
        ]);
    }

    public function setupTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        if ($user->two_factor_enabled) {
            return ApiResponse::success([
                'enabled' => true,
                'secret' => null,
                'provisioning_uri' => null,
            ]);
        }

        $secret = $this->twoFactorService->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->auditService->record('auth.2fa_setup_started', $user, $user, [], null, $request);

        return ApiResponse::success([
            'enabled' => false,
            'secret' => $secret,
            'provisioning_uri' => $this->twoFactorService->provisioningUri($user, $secret),
        ]);
    }

    public function enableTwoFactor(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6'], 'device_name' => ['nullable', 'string', 'max:120']]);
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        if (! $this->twoFactorService->verify($user, (string) $request->input('code'))) {
            $this->auditService->record('auth.2fa_enable_failed', $user, $user, [], null, $request);
            return ApiResponse::error('invalid_two_factor_code', 'The two-factor code is invalid.', 422, [
                'code' => ['The two-factor code is invalid.'],
            ]);
        }

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->twoFactorService->generateRecoveryCodes(),
            'last_login_at' => now(),
        ])->save();
        $request->user()?->currentAccessToken()?->delete();
        $this->auditService->record('auth.2fa_enabled', $user, $user, [], null, $request);

        return ApiResponse::success([
            'token' => $user->createToken((string) ($request->input('device_name') ?? 'DIS Command Center'), ['*'])->plainTextToken,
            'user' => MobileApiPayload::user($user->load(['roles', 'teams'])),
            'recovery_codes' => $user->two_factor_recovery_codes,
        ]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        if ($user->roles()->where('requires_two_factor', true)->exists()) {
            return ApiResponse::error('two_factor_required_by_role', 'Two-factor authentication is required by one or more assigned roles.', 422);
        }

        if (! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => ['The password is invalid.']]);
        }

        if (! $this->twoFactorService->verify($user, (string) $request->input('code'))) {
            $this->auditService->record('auth.2fa_disable_failed', $user, $user, [], null, $request);
            return ApiResponse::error('invalid_two_factor_code', 'The two-factor code is invalid.', 422, [
                'code' => ['The two-factor code is invalid.'],
            ]);
        }

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->auditService->record('auth.2fa_disabled', $user, $user, [], null, $request);

        return ApiResponse::success(MobileApiPayload::user($user->load(['roles', 'teams'])));
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::user($request->user()?->load(['roles', 'teams'])));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::success(null, 204);
    }

    private function currentTokenHasExactAbility(Request $request, string $ability): bool
    {
        $token = $request->user()?->currentAccessToken();
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];

        return in_array($ability, $abilities, true);
    }
}
