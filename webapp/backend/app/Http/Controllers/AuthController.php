<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TwoFactorService;
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

        $requiresTwoFactor = $user->roles->contains(fn ($role) => $role->requires_two_factor);
        if ($requiresTwoFactor && ! $user->two_factor_enabled) {
            return ApiResponse::error('forbidden', 'Two-factor authentication is required for this account.', 403);
        }

        $tokenName = $request->validated('device_name') ?? 'DIS API';

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
            'user' => $user,
        ]);
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6'], 'device_name' => ['nullable', 'string', 'max:120']]);
        $user = $request->user();

        if ($user === null || ! $request->user()?->tokenCan('2fa:pending')) {
            return ApiResponse::error('forbidden', 'A pending two-factor token is required.', 403);
        }

        if (! $this->twoFactorService->verify($user, (string) $request->input('code'))) {
            $this->auditService->record('auth.2fa_failed', $user, $user, [], null, $request);
            throw ValidationException::withMessages(['code' => ['The two-factor code is invalid.']]);
        }

        $request->user()?->currentAccessToken()?->delete();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'last_login_at' => now()])->save();
        $this->auditService->record('auth.2fa_verified', $user, $user, [], null, $request);

        return ApiResponse::success([
            'token' => $user->createToken((string) ($request->input('device_name') ?? 'DIS API'), ['*'])->plainTextToken,
            'user' => $user->load(['roles.permissions', 'teams']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($request->user()?->load(['roles.permissions', 'teams', 'statuses' => fn ($query) => $query->latest('effective_at')->limit(1)]));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::success(null, 204);
    }
}

