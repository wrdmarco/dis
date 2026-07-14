<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use App\Services\StoreReviewAccountService;
use App\Services\UserService;
use App\Services\WebSessionService;
use App\Support\ApiDateTime;
use App\Support\MobileApiPayload;
use App\Support\ProfileLocation;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class AuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$zvnIvo9cuVg.15AC9PNW9eW3zzEHuCVBySklFlmzGQtGz8w7TUtVi';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly StoreReviewAccountService $storeReviewAccountService,
        private readonly TwoFactorService $twoFactorService,
        private readonly UserService $userService,
        private readonly WebSessionService $webSessionService,
    ) {}

    public function csrfCookie(): Response
    {
        return response()->noContent();
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->validated('email')));
        $tokenName = (string) ($request->validated('device_name') ?? 'DIS API');
        $clientType = $this->requestedClientType($request, $tokenName);
        $isStatefulWebRequest = $this->webSessionService->isStatefulWebRequest($request);

        if ($clientType === 'web') {
            $this->webSessionService->assertStatefulWebRequest($request);
        } elseif ($isStatefulWebRequest) {
            $this->auditService->record('auth.login_client_context_blocked', User::class, null, [
                'email_hash' => hash('sha256', $email),
                'client_type' => $clientType,
            ], null, $request);

            return ApiResponse::error(
                'invalid_client_context',
                'Native application authentication cannot be used from the web console.',
                403,
            );
        }

        if ($limitedResponse = $this->loginRateLimitedResponse($request, $email)) {
            return $limitedResponse;
        }

        RateLimiter::hit($this->loginIpKey($request), 60);
        $user = User::query()
            ->with(['roles.permissions', 'teams'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
        $passwordValid = Hash::check(
            (string) $request->validated('password'),
            $user?->password ?? self::DUMMY_PASSWORD_HASH,
        );

        if ($user === null || ! $passwordValid) {
            if ($user !== null && ! $this->isLoginLocked($user)) {
                $this->recordFailedLoginAttempt($user, $request);
            }

            $this->auditService->record('auth.login_failed', User::class, null, [
                'email_hash' => hash('sha256', $email),
            ], null, $request);

            return $this->rejectLogin($request, $email);
        }

        if ($user->isStoreReviewAccount()) {
            if (! $this->storeReviewAccountService->canAuthenticate($user, $clientType)) {
                $this->auditService->record('auth.store_review_login_blocked', $user, $user, [
                    'client_type' => $clientType,
                ], null, $request);

                return $this->rejectLogin($request, $email);
            }

            RateLimiter::clear($this->loginIdentityKey($request, $email));
            $this->resetLoginFailures($user);

            return ApiResponse::success($this->storeReviewAccountService->authenticate(
                $user,
                $clientType,
                $tokenName,
                $request,
            ));
        }

        if ($user->account_status !== 'active') {
            $this->auditService->record('auth.login_blocked', $user, $user, ['account_status' => $user->account_status], null, $request);

            return $this->rejectLogin($request, $email);
        }

        if (! $this->canUseRequestedApp($user, $clientType)) {
            $this->auditService->record('auth.login_app_blocked', $user, $user, [
                'device_name' => $tokenName,
                'client_type' => $clientType,
            ], null, $request);

            return $this->rejectLogin($request, $email);
        }

        RateLimiter::clear($this->loginIdentityKey($request, $email));
        $this->resetLoginFailures($user);
        $requiresTwoFactor = $this->twoFactorService->isRequiredFor($user);
        $shouldChallengeTwoFactor = $requiresTwoFactor || $user->two_factor_enabled;

        if ($requiresTwoFactor && ! $user->two_factor_enabled) {
            $secret = is_string($user->two_factor_secret) && $user->two_factor_secret !== ''
                ? $user->two_factor_secret
                : $this->twoFactorService->generateSecret();
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => null,
            ])->save();
            $this->auditService->record('auth.2fa_setup_required', $user, $user, [], null, $request);

            $setup = [
                'enabled' => false,
                'secret' => $secret,
                'provisioning_uri' => $this->twoFactorService->provisioningUri($user, $secret),
            ];

            if ($clientType === 'web') {
                $this->webSessionService->beginPreAuthentication(
                    $request,
                    $user,
                    WebSessionService::PURPOSE_LOGIN_SETUP,
                    30,
                );

                return ApiResponse::success([
                    'requires_2fa' => false,
                    'requires_2fa_setup' => true,
                    'authenticated' => false,
                    'user' => MobileApiPayload::user($user),
                    'two_factor_setup' => $setup,
                ], 202);
            }

            return ApiResponse::success([
                'requires_2fa' => false,
                'requires_2fa_setup' => true,
                'token' => $user->createToken(
                    $tokenName,
                    ['2fa:setup', $this->clientAbility($clientType)],
                    now()->addMinutes(30),
                )->plainTextToken,
                'user' => MobileApiPayload::user($user),
                'two_factor_setup' => $setup,
            ], 202);
        }

        if ($shouldChallengeTwoFactor) {
            $this->auditService->record('auth.login_2fa_required', $user, $user, [], null, $request);

            if ($clientType === 'web') {
                $this->webSessionService->beginPreAuthentication(
                    $request,
                    $user,
                    WebSessionService::PURPOSE_LOGIN_CHALLENGE,
                    10,
                );

                return ApiResponse::success([
                    'requires_2fa' => true,
                    'authenticated' => false,
                ], 202);
            }

            return ApiResponse::success([
                'requires_2fa' => true,
                'token' => $user->createToken(
                    $tokenName,
                    ['2fa:pending', $this->clientAbility($clientType)],
                    now()->addMinutes(10),
                )->plainTextToken,
            ], 202);
        }

        $this->recordSuccessfulLogin($user, $request);

        if ($clientType === 'web') {
            $this->webSessionService->authenticate($request, $user);

            return ApiResponse::success([
                'requires_2fa' => false,
                'authenticated' => true,
                'user' => MobileApiPayload::user($user),
            ]);
        }

        return ApiResponse::success([
            'requires_2fa' => false,
            'token' => $user->createToken(
                $tokenName,
                ['*', $this->clientAbility($clientType)],
                $this->fullTokenExpiresAt(),
            )->plainTextToken,
            'user' => MobileApiPayload::user($user),
        ]);
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'client_type' => ['nullable', 'string', 'in:web,operator_android,operator_ios,admin_android,admin_ios'],
        ]);

        $pendingUser = $this->webSessionService->pendingUser($request, [
            WebSessionService::PURPOSE_LOGIN_CHALLENGE,
            WebSessionService::PURPOSE_REGISTRATION_CHALLENGE,
        ]);
        $registrationUser = $request->hasSession()
            && $request->session()->get(WebSessionService::KEY_PENDING_PURPOSE) === WebSessionService::PURPOSE_REGISTRATION_CHALLENGE
                ? $pendingUser
                : null;
        $isWebChallenge = $pendingUser !== null;
        $user = $pendingUser ?? $request->user();

        if ($user === null || (! $isWebChallenge && ! $this->currentTokenHasExactAbility($request, '2fa:pending'))) {
            return ApiResponse::error('forbidden', 'A pending two-factor challenge is required.', 403);
        }

        $challengeKey = $this->webSessionService->challengeKey($request);
        if (RateLimiter::tooManyAttempts($challengeKey, 5)) {
            $this->invalidateTwoFactorChallenge($request, $isWebChallenge);
            $this->auditService->record('auth.2fa_challenge_locked', $user, $user, [], null, $request);

            return $this->twoFactorLockedResponse($challengeKey);
        }

        if (! $this->twoFactorService->verifyForLogin($user, (string) $request->input('code'))) {
            RateLimiter::hit($challengeKey, 600);
            $this->auditService->record('auth.2fa_failed', $user, $user, [], null, $request);

            if (RateLimiter::attempts($challengeKey) >= 5) {
                $this->invalidateTwoFactorChallenge($request, $isWebChallenge);

                return $this->twoFactorLockedResponse($challengeKey);
            }

            return ApiResponse::error('invalid_two_factor_code', 'The two-factor code is invalid.', 422, [
                'code' => ['The two-factor code is invalid.'],
            ]);
        }

        RateLimiter::clear($challengeKey);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->recordSuccessfulLogin($user, $request);
        $this->auditService->record('auth.2fa_verified', $user, $user, [], null, $request);

        if ($isWebChallenge) {
            $authenticated = ! $user->isStoreReviewAccount();
            if ($authenticated) {
                $this->webSessionService->authenticate($request, $user);
            } elseif ($registrationUser !== null) {
                $this->webSessionService->beginPreAuthentication(
                    $request,
                    $user,
                    WebSessionService::PURPOSE_REGISTRATION_PAIRING,
                    30,
                );
            } else {
                $this->webSessionService->invalidate($request);
            }

            return ApiResponse::success([
                'authenticated' => $authenticated,
                'user' => MobileApiPayload::user($user->load(['roles.permissions', 'teams'])),
            ]);
        }

        $clientType = $this->clientTypeForTokenExchange($request);
        $this->deleteCurrentPersonalAccessToken($request);

        return ApiResponse::success([
            'token' => $user->createToken(
                (string) ($request->input('device_name') ?? 'DIS API'),
                ['*', $this->clientAbility($clientType)],
                $this->fullTokenExpiresAt(),
            )->plainTextToken,
            'user' => MobileApiPayload::user($user->load(['roles.permissions', 'teams'])),
        ]);
    }

    public function setupTwoFactor(Request $request): JsonResponse
    {
        $pendingUser = $this->webSessionService->pendingUser($request, [
            WebSessionService::PURPOSE_LOGIN_SETUP,
            WebSessionService::PURPOSE_REGISTRATION_SETUP,
        ]);
        $user = $pendingUser ?? $request->user();

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

        $secret = is_string($user->two_factor_secret) && $user->two_factor_secret !== ''
            ? $user->two_factor_secret
            : $this->twoFactorService->generateSecret();
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
        $request->validate([
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'client_type' => ['nullable', 'string', 'in:web,operator_android,operator_ios,admin_android,admin_ios'],
        ]);

        $pendingUser = $this->webSessionService->pendingUser($request, [
            WebSessionService::PURPOSE_LOGIN_SETUP,
            WebSessionService::PURPOSE_REGISTRATION_SETUP,
        ]);
        $registrationUser = $request->hasSession()
            && $request->session()->get(WebSessionService::KEY_PENDING_PURPOSE) === WebSessionService::PURPOSE_REGISTRATION_SETUP
                ? $pendingUser
                : null;
        $isWebSetup = $this->webSessionService->isStatefulWebRequest($request);
        $user = $pendingUser ?? $request->user();

        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        if (! $isWebSetup && ! $this->hasSetupOrFullToken($request)) {
            return ApiResponse::error('forbidden', 'A two-factor setup token is required.', 403);
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
        ])->save();
        $this->userService->revokeAuthenticationState(
            $user,
            $user,
            'auth.two_factor_enabled_sessions_revoked',
        );
        $user->refresh()->load(['roles.permissions', 'teams']);
        $this->recordSuccessfulLogin($user, $request);
        $this->auditService->record('auth.2fa_enabled', $user, $user, [], null, $request);

        if ($isWebSetup) {
            $authenticated = ! $user->isStoreReviewAccount();
            if ($authenticated) {
                $this->webSessionService->authenticate($request, $user);
            } elseif ($registrationUser !== null) {
                $this->webSessionService->beginPreAuthentication(
                    $request,
                    $user,
                    WebSessionService::PURPOSE_REGISTRATION_PAIRING,
                    30,
                );
            } else {
                $this->webSessionService->invalidate($request);
            }

            return ApiResponse::success([
                'authenticated' => $authenticated,
                'user' => MobileApiPayload::user($user->load(['roles.permissions', 'teams'])),
                'recovery_codes' => $user->two_factor_recovery_codes,
            ]);
        }

        $clientType = $this->clientTypeForTokenExchange($request);
        $this->deleteCurrentPersonalAccessToken($request);

        return ApiResponse::success([
            'token' => $user->createToken(
                (string) ($request->input('device_name') ?? 'DIS API'),
                ['*', $this->clientAbility($clientType)],
                $this->fullTokenExpiresAt(),
            )->plainTextToken,
            'user' => MobileApiPayload::user($user->load(['roles.permissions', 'teams'])),
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

        if ($this->twoFactorService->isRequiredFor($user)) {
            return ApiResponse::error('two_factor_required_globally', 'Multi-factor authentication is globaal verplicht.', 422);
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

        $isWebSession = $this->webSessionService->isStatefulWebRequest($request);
        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->userService->revokeAuthenticationState(
            $user,
            $user,
            'auth.two_factor_disabled_sessions_revoked',
        );
        $user->refresh()->load(['roles.permissions', 'teams']);

        if ($isWebSession) {
            $this->webSessionService->authenticate($request, $user);
        }

        $this->auditService->record('auth.2fa_disabled', $user, $user, [], null, $request);

        return ApiResponse::success(MobileApiPayload::user($user));
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::user($request->user()?->load(['roles.permissions', 'teams'])));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'home_city' => ['nullable', 'string', 'max:120'],
            'home_region' => ['nullable', 'string', 'max:120'],
            'home_country' => ['nullable', 'string', 'size:2', Rule::in(ProfileLocation::countryCodes())],
            'theme' => ['sometimes', 'string', 'in:dark,light'],
        ]);

        return ApiResponse::success(MobileApiPayload::user($this->userService->updateOwnProfile($request->user(), $data)));
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();
        if ($user !== null) {
            $this->auditService->record('auth.logout', $user, $user, [], null, $request);
        }

        if ($this->webSessionService->isStatefulWebRequest($request)) {
            $this->webSessionService->invalidate($request);
        } else {
            $this->deleteCurrentPersonalAccessToken($request);
        }

        return response()->noContent();
    }

    private function currentTokenHasExactAbility(Request $request, string $ability): bool
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        $abilities = is_array($token->abilities) ? $token->abilities : [];

        return in_array($ability, $abilities, true);
    }

    private function hasSetupOrFullToken(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return $this->webSessionService->isStatefulWebRequest($request);
        }

        $abilities = is_array($token->abilities) ? $token->abilities : [];

        return in_array('*', $abilities, true)
            || in_array('2fa:setup', $abilities, true)
            || in_array('registration:2fa', $abilities, true);
    }

    private function requestedClientType(Request $request, string $tokenName): string
    {
        $clientType = method_exists($request, 'validated')
            ? ($request->validated('client_type') ?? $request->input('client_type'))
            : $request->input('client_type');
        if (in_array($clientType, ['web', 'operator_android', 'operator_ios', 'admin_android', 'admin_ios'], true)) {
            return (string) $clientType;
        }

        $normalizedTokenName = strtolower($tokenName);

        if (str_contains($normalizedTokenName, 'admin android')) {
            return 'admin_android';
        }

        if (str_contains($normalizedTokenName, 'ios') || str_contains($normalizedTokenName, 'iphone')) {
            return 'operator_ios';
        }

        if (str_contains($normalizedTokenName, 'android')) {
            return 'operator_android';
        }

        return 'web';
    }

    private function clientTypeForTokenExchange(Request $request): string
    {
        $clientType = $request->input('client_type');
        if (in_array($clientType, ['operator_android', 'operator_ios', 'admin_android', 'admin_ios'], true)) {
            return (string) $clientType;
        }

        $token = $request->user()?->currentAccessToken();
        $abilities = $token instanceof PersonalAccessToken && is_array($token->abilities) ? $token->abilities : [];
        foreach (['operator_android', 'operator_ios', 'admin_android', 'admin_ios'] as $candidate) {
            if (in_array($this->clientAbility($candidate), $abilities, true)) {
                return $candidate;
            }
        }

        $tokenName = $token instanceof PersonalAccessToken && is_string($token->name)
            ? $token->name
            : (string) ($request->input('device_name') ?? 'DIS API');

        return $this->requestedClientType($request, $tokenName);
    }

    private function clientAbility(string $clientType): string
    {
        return match ($clientType) {
            'operator_android', 'operator_ios' => 'client:operator',
            'admin_android', 'admin_ios' => 'client:admin',
            default => 'client:web',
        };
    }

    private function canUseRequestedApp(User $user, string $clientType): bool
    {
        return match ($clientType) {
            'operator_android', 'operator_ios' => $user->canUseOperatorApp(),
            'admin_android', 'admin_ios' => $user->canUseAdminApp(),
            'web' => ! $user->isStoreReviewAccount(),
            default => false,
        };
    }

    private function fullTokenExpiresAt(): DateTimeInterface
    {
        return now()->addDays(180);
    }

    private function invalidateTwoFactorChallenge(Request $request, bool $isWebChallenge): void
    {
        if ($isWebChallenge) {
            $this->webSessionService->invalidate($request);

            return;
        }

        $this->deleteCurrentPersonalAccessToken($request);
    }

    private function deleteCurrentPersonalAccessToken(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    private function twoFactorLockedResponse(string $challengeKey): JsonResponse
    {
        return ApiResponse::error(
            'two_factor_challenge_locked',
            'Te veel mislukte pogingen. Log opnieuw in.',
            429,
        )->header('Retry-After', (string) max(1, RateLimiter::availableIn($challengeKey)));
    }

    private function loginRateLimitedResponse(Request $request, string $email): ?JsonResponse
    {
        $ipKey = $this->loginIpKey($request);
        $identityKey = $this->loginIdentityKey($request, $email);

        if (! RateLimiter::tooManyAttempts($ipKey, 20)
            && ! RateLimiter::tooManyAttempts($identityKey, 5)) {
            return null;
        }

        $retryAfter = max(
            RateLimiter::availableIn($ipKey),
            RateLimiter::availableIn($identityKey),
            1,
        );

        return ApiResponse::error('rate_limited', 'Too many login attempts.', 429)
            ->header('Retry-After', (string) $retryAfter);
    }

    private function rejectLogin(Request $request, string $email): JsonResponse
    {
        RateLimiter::hit($this->loginIdentityKey($request, $email), 300);

        return ApiResponse::error('invalid_credentials', 'The provided credentials are invalid.', 422, [
            'email' => ['The provided credentials are invalid.'],
        ]);
    }

    private function loginIpKey(Request $request): string
    {
        return 'login:ip:'.hash('sha256', (string) $request->ip());
    }

    private function loginIdentityKey(Request $request, string $email): string
    {
        return 'login:identity:'.hash('sha256', (string) $request->ip().'|'.$email);
    }

    private function isLoginLocked(User $user): bool
    {
        if ($user->login_locked_until === null) {
            return false;
        }

        if ($user->login_locked_until->isFuture()) {
            return true;
        }

        $this->resetLoginFailures($user);

        return false;
    }

    private function recordFailedLoginAttempt(User $user, Request $request): void
    {
        $attempts = min(255, (int) $user->failed_login_attempts + 1);
        $lockedUntil = $attempts >= 5 ? now()->addMinutes(5) : null;
        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'login_locked_until' => $lockedUntil,
        ])->save();

        if ($lockedUntil !== null) {
            $this->auditService->record('auth.login_lockout_started', $user, $user, [
                'failed_login_attempts' => $attempts,
                'login_locked_until' => ApiDateTime::dateTime($lockedUntil),
            ], null, $request);
        }
    }

    private function resetLoginFailures(User $user): void
    {
        if ((int) $user->failed_login_attempts === 0 && $user->login_locked_until === null) {
            return;
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'login_locked_until' => null,
        ])->save();
    }

    private function recordSuccessfulLogin(User $user, Request $request): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'failed_login_attempts' => 0,
            'login_locked_until' => null,
        ])->save();
        $this->auditService->record('auth.login_succeeded', $user, $user, [], null, $request);
    }
}
