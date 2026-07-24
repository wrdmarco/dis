<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MobilePairingService;
use App\Services\PasswordPolicy;
use App\Services\SoftwareDownloadService;
use App\Services\TwoFactorService;
use App\Services\UserService;
use App\Services\WebSessionService;
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
        private readonly MobilePairingService $mobilePairingService,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly TwoFactorService $twoFactorService,
        private readonly UserService $userService,
        private readonly WebSessionService $webSessionService,
        private readonly SoftwareDownloadService $softwareDownloads,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->webSessionService->assertStatefulWebRequest($request);

        $data = $request->validate([
            'email' => ['nullable', 'required_with:token', 'email:rfc'],
            'token' => ['nullable', 'required_with:email', 'string'],
        ]);

        $hasInvitation = isset($data['email'], $data['token']);
        $user = $hasInvitation
            ? $this->userForToken((string) $data['email'], (string) $data['token'])
            : $this->webSessionService->pendingUser($request, [WebSessionService::PURPOSE_REGISTRATION_ACCOUNT]);
        if ($user === null) {
            return ApiResponse::error('invalid_invitation', 'Registratielink is ongeldig of verlopen.', 422);
        }

        $user->load(['roles.permissions', 'teams']);
        if ($hasInvitation) {
            $this->webSessionService->beginPreAuthentication(
                $request,
                $user,
                WebSessionService::PURPOSE_REGISTRATION_ACCOUNT,
                30,
            );
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        }

        return ApiResponse::success([
            'user' => MobileApiPayload::user($user),
            'requires_mfa' => $this->requiresMfa($user),
            'admin_app_allowed' => $this->adminAppAllowed($user),
            'download_options' => ['channels' => $this->softwareDownloads->channels()],
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $this->webSessionService->assertStatefulWebRequest($request);

        $data = $request->validate([
            'password' => ['required', 'confirmed', $this->passwordPolicy->rule()],
        ]);

        $user = $this->webSessionService->pendingUser($request, [
            WebSessionService::PURPOSE_REGISTRATION_ACCOUNT,
        ]);
        if ($user === null) {
            return ApiResponse::error('invalid_invitation', 'Registratielink is ongeldig of verlopen.', 422);
        }

        $user->forceFill([
            'password' => Hash::make((string) $data['password']),
            'failed_login_attempts' => 0,
            'login_locked_until' => null,
        ])->save();
        $this->userService->revokeAuthenticationState(
            $user,
            $user,
            'users.registration_credentials_changed_sessions_revoked',
        );

        $user->refresh()->load(['roles.permissions', 'teams']);
        $requiresMfa = $this->requiresMfa($user);
        $twoFactorSetup = null;
        $authenticated = false;

        $requiresChallenge = $requiresMfa && $user->two_factor_enabled;

        if ($requiresMfa && ! $user->two_factor_enabled) {
            $secret = is_string($user->two_factor_secret) && $user->two_factor_secret !== ''
                ? $user->two_factor_secret
                : $this->twoFactorService->generateSecret();
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => null,
            ])->save();
            $twoFactorSetup = [
                'enabled' => false,
                'secret' => $secret,
                'provisioning_uri' => $this->twoFactorService->provisioningUri($user, $secret),
            ];
            $this->webSessionService->beginPreAuthentication(
                $request,
                $user,
                WebSessionService::PURPOSE_REGISTRATION_SETUP,
                30,
            );
        } elseif ($requiresChallenge) {
            $this->webSessionService->beginPreAuthentication(
                $request,
                $user,
                WebSessionService::PURPOSE_REGISTRATION_CHALLENGE,
                10,
            );
        } else {
            $user->forceFill(['last_login_at' => now()])->save();
            $authenticated = ! $user->isStoreReviewAccount();
            if ($authenticated) {
                $this->webSessionService->authenticate($request, $user);
            } else {
                $this->webSessionService->beginPreAuthentication(
                    $request,
                    $user,
                    WebSessionService::PURPOSE_REGISTRATION_PAIRING,
                    30,
                );
            }
        }

        $this->auditService->record('users.registration_completed', $user, $user, [
            'requires_mfa' => $requiresMfa,
        ], null, $request);

        return ApiResponse::success([
            'authenticated' => $authenticated,
            'user' => MobileApiPayload::user($user->refresh()->load(['roles.permissions', 'teams'])),
            'requires_mfa' => $requiresMfa,
            'requires_2fa' => $requiresChallenge,
            'two_factor_setup' => $twoFactorSetup,
            'admin_app_allowed' => $this->adminAppAllowed($user),
            'download_options' => ['channels' => $this->softwareDownloads->channels()],
        ]);
    }

    public function mobilePairing(Request $request): JsonResponse
    {
        $this->webSessionService->assertStatefulWebRequest($request);

        $data = $request->validate([
            'client_type' => ['required', 'string', 'in:operator_android,operator_ios'],
        ]);
        $user = $this->webSessionService->pendingUser($request, [
            WebSessionService::PURPOSE_REGISTRATION_PAIRING,
        ]) ?? $request->user();

        if ($user === null) {
            return ApiResponse::error('registration_session_expired', 'De registratiesessie is verlopen.', 401);
        }

        $clientType = (string) $data['client_type'];
        if (! $this->mobilePairingService->canUseClient($user, $clientType)) {
            return ApiResponse::error('mobile_app_forbidden', 'Dit account heeft geen toegang tot de operator-app.', 403);
        }

        return ApiResponse::success($this->mobilePairingService->create($user, $clientType, $request), 201);
    }

    private function userForToken(string $email, string $token): ?User
    {
        $user = User::query()->with(['roles.permissions', 'teams'])->where('email', $email)->first();

        if ($user === null
            || $user->account_status !== 'active'
            || ! Password::broker()->tokenExists($user, $token)) {
            return null;
        }

        return $user;
    }

    private function requiresMfa(User $user): bool
    {
        return $this->twoFactorService->isRequiredFor($user);
    }

    private function adminAppAllowed(User $user): bool
    {
        return $user->canUseAdminApp();
    }
}
