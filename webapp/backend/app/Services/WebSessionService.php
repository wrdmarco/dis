<?php

namespace App\Services;

use App\Http\Responses\ApiResponse;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class WebSessionService
{
    public const PURPOSE_LOGIN_CHALLENGE = 'login_challenge';

    public const PURPOSE_LOGIN_SETUP = 'login_setup';

    public const PURPOSE_REGISTRATION_SETUP = 'registration_setup';

    public const PURPOSE_REGISTRATION_ACCOUNT = 'registration_account';

    public const PURPOSE_REGISTRATION_CHALLENGE = 'registration_challenge';

    public const PURPOSE_REGISTRATION_PAIRING = 'registration_pairing';

    public const KEY_PENDING_USER_ID = 'dis.auth.pending_user_id';

    public const KEY_PENDING_PURPOSE = 'dis.auth.pending_purpose';

    public const KEY_PENDING_EXPIRES_AT = 'dis.auth.pending_expires_at';

    public const KEY_PENDING_VERSION = 'dis.auth.pending_version';

    public const KEY_AUTHENTICATED_AT = 'dis.auth.authenticated_at';

    public const KEY_LAST_ACTIVITY_AT = 'dis.auth.last_activity_at';

    public const KEY_AUTH_VERSION = 'dis.auth.version';

    public function isStatefulWebRequest(Request $request): bool
    {
        return $request->attributes->getBoolean('sanctum')
            && $request->hasSession()
            && ! ($request->user()?->currentAccessToken() instanceof PersonalAccessToken);
    }

    public function assertStatefulWebRequest(Request $request): void
    {
        if ($this->isStatefulWebRequest($request)) {
            return;
        }

        throw new HttpResponseException(
            ApiResponse::error('stateful_web_session_required', 'A secure web session is required.', 403),
        );
    }

    public function beginPreAuthentication(Request $request, User $user, string $purpose, int $lifetimeMinutes): void
    {
        $this->assertStatefulWebRequest($request);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put([
            self::KEY_PENDING_USER_ID => $user->getKey(),
            self::KEY_PENDING_PURPOSE => $purpose,
            self::KEY_PENDING_EXPIRES_AT => now()->addMinutes($lifetimeMinutes)->getTimestamp(),
            self::KEY_PENDING_VERSION => (int) $user->auth_session_version,
        ]);
    }

    /**
     * @param  list<string>  $allowedPurposes
     */
    public function pendingUser(Request $request, array $allowedPurposes): ?User
    {
        if (! $this->isStatefulWebRequest($request)) {
            return null;
        }

        $session = $request->session();
        $userId = $session->get(self::KEY_PENDING_USER_ID);
        $purpose = $session->get(self::KEY_PENDING_PURPOSE);
        $expiresAt = $session->get(self::KEY_PENDING_EXPIRES_AT);
        $version = $session->get(self::KEY_PENDING_VERSION);

        if ($userId === null && $purpose === null && $expiresAt === null && $version === null) {
            return null;
        }

        if (! is_string($userId)
            || ! is_string($purpose)
            || ! in_array($purpose, $allowedPurposes, true)
            || ! is_int($expiresAt)
            || $expiresAt <= now()->getTimestamp()
            || ! is_int($version)) {
            $this->invalidate($request);

            return null;
        }

        $user = User::query()->with(['roles.permissions', 'teams'])->find($userId);
        if ($user === null
            || $user->account_status !== 'active'
            || (int) $user->auth_session_version !== $version) {
            $this->invalidate($request);

            return null;
        }

        return $user;
    }

    public function authenticate(Request $request, User $user): void
    {
        $this->assertStatefulWebRequest($request);

        Auth::guard('web')->login($user);
        $request->session()->regenerate(true);
        $this->forgetPending($request);

        $timestamp = now()->getTimestamp();
        $request->session()->put([
            self::KEY_AUTHENTICATED_AT => $timestamp,
            self::KEY_LAST_ACTIVITY_AT => $timestamp,
            self::KEY_AUTH_VERSION => (int) $user->auth_session_version,
        ]);
    }

    public function invalidate(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function forgetPending(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget([
            self::KEY_PENDING_USER_ID,
            self::KEY_PENDING_PURPOSE,
            self::KEY_PENDING_EXPIRES_AT,
            self::KEY_PENDING_VERSION,
        ]);
    }

    public function challengeKey(Request $request): string
    {
        if ($this->isStatefulWebRequest($request)) {
            return 'two-factor-challenge:web:'.hash('sha256', $request->session()->getId());
        }

        $tokenId = $request->user()?->currentAccessToken()?->getKey();

        return 'two-factor-challenge:token:'.($tokenId ?? hash('sha256', (string) $request->ip()));
    }
}
