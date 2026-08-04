<?php

namespace App\Services;

use App\Http\Responses\ApiResponse;
use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\WebLoginApproval;
use App\Models\WebLoginApprovalRecipient;
use App\Repositories\WebLoginApprovalRepository;
use App\Support\ApiDateTime;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class WebLoginApprovalService
{
    public const CAPABILITY = 'web_login_approval_v1';

    public const PUSH_TYPE = 'web_login_approval';

    private const LIFETIME_SECONDS = 120;

    private const POLL_AFTER_SECONDS = 2;

    private const PUSH_COOLDOWN_SECONDS = 15;

    private const PUSHES_PER_HOUR = 6;

    public function __construct(
        private readonly WebLoginApprovalRepository $approvals,
        private readonly MobileDeviceSessionService $mobileSessions,
        private readonly TwoFactorService $twoFactorService,
        private readonly AuditService $auditService,
    ) {}

    /** @return array<string, mixed> */
    public function start(Request $request, User $user): array
    {
        $devices = $this->capableDevices($user);
        if ($devices->isEmpty()) {
            return $this->unavailableBrowserPayload();
        }
        $allowance = $this->claimPushAllowance($user);
        if (! $allowance['allowed']) {
            $this->auditPushRateLimit($request, $user, 'login', $allowance['retry_after_seconds']);

            return $this->unavailableBrowserPayload();
        }

        $sessionHash = $this->browserSessionHash($request);
        $now = now();
        $approval = DB::transaction(function () use ($request, $user, $devices, $sessionHash, $now): ?WebLoginApproval {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (! $lockedUser instanceof User
                || $lockedUser->account_status !== 'active'
                || ! (bool) $lockedUser->push_enabled
                || (int) $lockedUser->auth_session_version !== (int) $user->auth_session_version) {
                return null;
            }
            $created = $this->approvals->create([
                'user_id' => $lockedUser->id,
                'browser_session_hash' => $sessionHash,
                'auth_session_version' => (int) $lockedUser->auth_session_version,
                'status' => 'pending',
                'verification_number' => (string) random_int(100, 999),
                'request_device' => $this->requestDevice($request),
                'request_ip' => mb_substr((string) $request->ip(), 0, 64) ?: null,
                'requested_at' => $now,
                'expires_at' => $now->copy()->addSeconds(self::LIFETIME_SECONDS),
            ]);
            if (! $created instanceof WebLoginApproval) {
                throw new \LogicException('Web login approval repository returned an unexpected model.');
            }

            foreach ($devices as $device) {
                $created->recipients()->create([
                    'fcm_token_id' => $device->id,
                    'personal_access_token_id' => $device->personal_access_token_id,
                    'delivery_status' => 'queued',
                ]);
            }

            $this->auditService->record('auth.web_login_approval_requested', $created, $lockedUser, [
                'recipient_count' => $devices->count(),
                'expires_at' => ApiDateTime::dateTime($created->expires_at),
            ], null, $request);

            return $created->load('recipients');
        }, 3);

        if (! $approval instanceof WebLoginApproval) {
            return $this->unavailableBrowserPayload();
        }

        $this->dispatchPushes($approval);

        return $this->browserPayload($approval);
    }

    /** @return array<string, mixed> */
    public function browserStatus(Request $request, User $user): array
    {
        $approval = DB::transaction(function () use ($request, $user): ?WebLoginApproval {
            $approval = $this->approvals->findForBrowserSession(
                $this->browserSessionHash($request),
                true,
            );
            if (! $this->matchesBrowserUser($approval, $user)) {
                return null;
            }

            $this->persistExpiration($approval);

            return $approval;
        }, 3);

        return $approval === null
            ? $this->unavailableBrowserPayload()
            : $this->browserPayload($approval);
    }

    /** @return array<string, mixed> */
    public function resend(Request $request, User $user): array
    {
        $devices = $this->capableDevices($user);
        if ($devices->isEmpty()) {
            return $this->unavailableBrowserPayload();
        }
        $allowance = $this->claimPushAllowance($user);
        if (! $allowance['allowed']) {
            $this->auditPushRateLimit($request, $user, 'resend', $allowance['retry_after_seconds']);
            $this->pushRateLimited($allowance['retry_after_seconds']);
        }

        $sessionHash = $this->browserSessionHash($request);
        $approval = DB::transaction(function () use ($request, $user, $devices, $sessionHash): WebLoginApproval {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (! $lockedUser instanceof User
                || $lockedUser->account_status !== 'active'
                || ! (bool) $lockedUser->push_enabled
                || (int) $lockedUser->auth_session_version !== (int) $user->auth_session_version) {
                $this->notFound();
            }
            $now = now();
            $previousApproval = $this->approvals->findForBrowserSession($sessionHash, true);
            if ($previousApproval !== null && ! $this->matchesBrowserUser($previousApproval, $lockedUser)) {
                $this->notFound();
            }
            if ($previousApproval !== null
                && ! in_array($this->effectiveStatus($previousApproval), ['pending', 'expired'], true)) {
                $this->handled();
            }

            if ($previousApproval !== null) {
                $previousApproval->forceFill([
                    'browser_session_hash' => null,
                    'status' => 'cancelled',
                    'cancelled_at' => $now,
                ])->save();
                $this->auditService->record(
                    'auth.web_login_approval_cancelled',
                    $previousApproval,
                    $lockedUser,
                    ['reason' => 'superseded_by_resend'],
                    null,
                    $request,
                );
            }

            $created = $this->approvals->create([
                'user_id' => $lockedUser->id,
                'browser_session_hash' => $sessionHash,
                'auth_session_version' => (int) $lockedUser->auth_session_version,
                'status' => 'pending',
                'verification_number' => (string) random_int(100, 999),
                'request_device' => $this->requestDevice($request),
                'request_ip' => mb_substr((string) $request->ip(), 0, 64) ?: null,
                'requested_at' => $now,
                'expires_at' => $now->copy()->addSeconds(self::LIFETIME_SECONDS),
            ]);
            if (! $created instanceof WebLoginApproval) {
                throw new \LogicException('Web login approval repository returned an unexpected model.');
            }

            foreach ($devices as $device) {
                $created->recipients()->create([
                    'fcm_token_id' => $device->id,
                    'personal_access_token_id' => $device->personal_access_token_id,
                    'delivery_status' => 'queued',
                ]);
            }

            $this->auditService->record('auth.web_login_approval_resent', $created, $lockedUser, [
                'recipient_count' => $devices->count(),
                'expires_at' => ApiDateTime::dateTime($created->expires_at),
            ], null, $request);

            return $created->load('recipients');
        }, 3);

        $this->dispatchPushes($approval);

        return $this->browserPayload($approval);
    }

    public function consume(Request $request, User $user): User
    {
        $result = DB::transaction(function () use ($request, $user): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $approval = $this->approvals->findForBrowserSession(
                $this->browserSessionHash($request),
                true,
            );
            if ($lockedUser === null || ! $this->matchesBrowserUser($approval, $lockedUser)) {
                return ['error' => 'handled'];
            }

            if ($this->effectiveStatus($approval) === 'expired') {
                $approval->forceFill(['status' => 'expired'])->save();

                return ['error' => 'expired'];
            }
            if ($approval->status !== 'approved') {
                return ['error' => 'handled'];
            }

            $approval->forceFill([
                'status' => 'consumed',
                'consumed_at' => now(),
            ])->save();
            $lockedUser->forceFill([
                'last_login_at' => now(),
                'failed_login_attempts' => 0,
                'login_locked_until' => null,
                'two_factor_confirmed_at' => now(),
            ])->save();
            $this->auditService->record('auth.login_succeeded', $lockedUser, $lockedUser, [], null, $request);
            $this->auditService->record('auth.2fa_verified', $lockedUser, $lockedUser, [
                'method' => 'mobile_approval',
            ], null, $request);
            $this->auditService->record('auth.web_login_approval_consumed', $approval, $lockedUser, [], null, $request);

            return ['user' => $lockedUser];
        }, 3);

        if (($result['error'] ?? null) === 'expired') {
            $this->expired();
        }
        if (! ($result['user'] ?? null) instanceof User) {
            $this->handled();
        }

        return $result['user'];
    }

    public function verifyAlternativeFactor(Request $request, User $user, string $code): string
    {
        // TOTP replay protection is cache-backed and is not rolled back with
        // the database transaction. Never automatically rerun verification.
        return DB::transaction(function () use ($request, $user, $code): string {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($lockedUser === null) {
                return 'denied';
            }
            $approval = $this->approvals->findForBrowserSession(
                $this->browserSessionHash($request),
                true,
            );
            if ($this->matchesBrowserUser($approval, $lockedUser) && $approval->status === 'denied') {
                return 'denied';
            }

            if (! $this->twoFactorService->verifyForLogin($lockedUser, $code)) {
                return 'invalid';
            }

            if ($this->matchesBrowserUser($approval, $lockedUser)
                && in_array($approval->status, ['pending', 'approved'], true)) {
                $approval->forceFill([
                    'status' => $this->effectiveStatus($approval) === 'expired' ? 'expired' : 'cancelled',
                    'cancelled_at' => now(),
                ])->save();
                $this->auditService->record('auth.web_login_approval_cancelled', $approval, $lockedUser, [
                    'reason' => 'alternate_two_factor_method',
                ], null, $request);
            }

            return 'verified';
        }, 1);
    }

    /** @return array<string, mixed> */
    public function showForApp(Request $request, string $approvalId): array
    {
        [$user, $accessToken, $device] = $this->targetedDevice($request, $approvalId);
        $approval = DB::transaction(function () use ($approvalId, $user, $accessToken, $device): ?WebLoginApproval {
            $approval = $this->approvals->lockForTargetedDevice(
                $approvalId,
                (string) $user->id,
                (string) $device->id,
                (string) $accessToken->id,
            );
            if ($approval !== null
                && (int) $approval->auth_session_version === (int) $user->auth_session_version) {
                $this->persistExpiration($approval);

                return $approval;
            }

            return null;
        }, 3);

        if ($approval === null) {
            $this->notFound();
        }

        return $this->appPayload($approval);
    }

    /** @return array<string, mixed> */
    public function decide(Request $request, string $approvalId, bool $approve): array
    {
        [$user, $accessToken, $device] = $this->targetedDevice($request, $approvalId);
        $result = DB::transaction(function () use (
            $request,
            $approvalId,
            $approve,
            $user,
            $accessToken,
            $device,
        ): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($lockedUser === null) {
                return ['error' => 'not_found'];
            }
            $liveAccessToken = $this->mobileSessions->liveTokenForClient(
                $lockedUser,
                (string) $accessToken->id,
                'operator',
                true,
            );
            $lockedDevice = FcmToken::query()
                ->whereKey($device->id)
                ->where('user_id', $lockedUser->id)
                ->where('client_type', 'operator')
                ->where('is_active', true)
                ->where('personal_access_token_id', $liveAccessToken?->id)
                ->whereJsonContains('capabilities', self::CAPABILITY)
                ->lockForUpdate()
                ->first();
            if ($liveAccessToken === null
                || $lockedDevice === null
                || ! $lockedDevice->isReachableFor($lockedUser, $liveAccessToken)) {
                return ['error' => 'not_found'];
            }

            $approval = $this->approvals->lockForTargetedDevice(
                $approvalId,
                (string) $lockedUser->id,
                (string) $lockedDevice->id,
                (string) $liveAccessToken->id,
            );
            if ($approval === null) {
                return ['error' => 'not_found'];
            }
            if ((int) $approval->auth_session_version !== (int) $lockedUser->auth_session_version) {
                return ['error' => 'not_found'];
            }
            if ($this->effectiveStatus($approval) === 'expired') {
                $approval->forceFill(['status' => 'expired'])->save();

                return ['error' => 'expired'];
            }
            if ($approval->status !== 'pending') {
                return ['error' => 'handled'];
            }

            $now = now();
            $approval->forceFill($approve ? [
                'status' => 'approved',
                'approved_at' => $now,
                'approved_by_fcm_token_id' => $lockedDevice->id,
                'approved_by_personal_access_token_id' => $liveAccessToken->id,
            ] : [
                'status' => 'denied',
                'denied_at' => $now,
            ])->save();

            $this->auditService->record(
                $approve ? 'auth.web_login_approval_approved' : 'auth.web_login_approval_denied',
                $approval,
                $lockedUser,
                ['fcm_token_id' => (string) $lockedDevice->id],
                null,
                $request,
            );

            return ['approval' => $approval];
        }, 3);

        return match ($result['error'] ?? null) {
            'not_found' => $this->notFound(),
            'expired' => $this->expired(),
            'handled' => $this->handled(),
            default => $this->appPayload($result['approval']),
        };
    }

    public function markDelivery(string $recipientId, bool $successful): void
    {
        WebLoginApprovalRecipient::query()->whereKey($recipientId)->update($successful ? [
            'delivery_status' => 'sent',
            'last_sent_at' => now(),
            'delivery_failed_at' => null,
            'updated_at' => now(),
        ] : [
            'delivery_status' => 'failed',
            'delivery_failed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function mayDeliver(string $recipientId, FcmToken $device): bool
    {
        $capabilities = is_array($device->capabilities) ? $device->capabilities : [];
        $user = User::query()->whereKey($device->user_id)->first();
        $accessToken = PersonalAccessToken::query()->whereKey($device->personal_access_token_id)->first();
        if (! $user instanceof User
            || ! $accessToken instanceof PersonalAccessToken
            || ! $device->isReachableFor($user, $accessToken)
            || ! in_array(self::CAPABILITY, $capabilities, true)
            || trim((string) $device->personal_access_token_id) === '') {
            return false;
        }

        return WebLoginApprovalRecipient::query()
            ->whereKey($recipientId)
            ->where('fcm_token_id', $device->id)
            ->where('personal_access_token_id', $device->personal_access_token_id)
            ->whereHas('approval', fn ($approvals) => $approvals
                ->where('user_id', $device->user_id)
                ->where('auth_session_version', (int) $user->auth_session_version)
                ->where('status', 'pending')
                ->where('expires_at', '>', now()))
            ->exists();
    }

    /** @return Collection<int, FcmToken> */
    private function capableDevices(User $user): Collection
    {
        return FcmToken::query()
            ->reachable()
            ->where('user_id', $user->id)
            ->where('client_type', 'operator')
            ->whereJsonContains('capabilities', self::CAPABILITY)
            ->with('personalAccessToken')
            ->get()
            ->filter(fn (FcmToken $device): bool => $device->isReachableFor($user, $device->personalAccessToken))
            ->values();
    }

    private function dispatchPushes(WebLoginApproval $approval): void
    {
        foreach ($approval->recipients as $recipient) {
            if ($recipient->fcm_token_id === null || $recipient->personal_access_token_id === null) {
                continue;
            }

            $isCurrentCapableTarget = FcmToken::query()
                ->reachable()
                ->whereKey($recipient->fcm_token_id)
                ->where('user_id', $approval->user_id)
                ->where('client_type', 'operator')
                ->where('personal_access_token_id', $recipient->personal_access_token_id)
                ->whereJsonContains('capabilities', self::CAPABILITY)
                ->exists();
            if (! $isCurrentCapableTarget) {
                $this->markDelivery((string) $recipient->id, false);

                continue;
            }

            try {
                SendFcmNotification::dispatch(
                    fcmTokenId: (string) $recipient->fcm_token_id,
                    messageType: self::PUSH_TYPE,
                    title: 'Inlogverzoek',
                    body: 'Open de app om een inlogverzoek veilig te beoordelen.',
                    data: [
                        'type' => self::PUSH_TYPE,
                        'login_approval_id' => (string) $approval->id,
                    ],
                    webLoginApprovalRecipientId: (string) $recipient->id,
                );
            } catch (\Throwable $exception) {
                $this->markDelivery((string) $recipient->id, false);
                report($exception);
            }
        }
    }

    /** @return array{User, PersonalAccessToken, FcmToken} */
    private function targetedDevice(Request $request, string $approvalId): array
    {
        $user = $request->user();
        $accessToken = $user?->currentAccessToken();
        $abilities = is_array($accessToken?->abilities ?? null) ? $accessToken->abilities : [];
        if (! $user instanceof User
            || ! $accessToken instanceof PersonalAccessToken
            || ! in_array('*', $abilities, true)
            || ! in_array('client:operator', $abilities, true)) {
            $this->notFound();
        }

        $device = FcmToken::query()
            ->where('user_id', $user->id)
            ->where('personal_access_token_id', $accessToken->id)
            ->where('client_type', 'operator')
            ->where('is_active', true)
            ->whereJsonContains('capabilities', self::CAPABILITY)
            ->whereHas('webLoginApprovalRecipients', fn ($recipients) => $recipients
                ->where('web_login_approval_id', $approvalId)
                ->where('personal_access_token_id', $accessToken->id))
            ->first();
        if ($device === null) {
            $this->notFound();
        }
        if (! $device->isReachableFor($user, $accessToken)) {
            $this->notFound();
        }

        return [$user, $accessToken, $device];
    }

    private function matchesBrowserUser(?WebLoginApproval $approval, User $user): bool
    {
        return $approval !== null
            && (string) $approval->user_id === (string) $user->id
            && (int) $approval->auth_session_version === (int) $user->auth_session_version;
    }

    private function persistExpiration(WebLoginApproval $approval): void
    {
        if ($this->effectiveStatus($approval) !== 'expired' || $approval->status === 'expired') {
            return;
        }

        $approval->forceFill(['status' => 'expired'])->save();
    }

    private function effectiveStatus(WebLoginApproval $approval): string
    {
        if (in_array($approval->status, ['pending', 'approved'], true)
            && $approval->expires_at->lessThanOrEqualTo(now())) {
            return 'expired';
        }

        return (string) $approval->status;
    }

    /** @return array<string, mixed> */
    private function browserPayload(WebLoginApproval $approval): array
    {
        return [
            'available' => true,
            'status' => $this->effectiveStatus($approval),
            'expires_at' => ApiDateTime::dateTime($approval->expires_at),
            'poll_after_seconds' => self::POLL_AFTER_SECONDS,
            'verification_number' => $approval->verification_number,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableBrowserPayload(): array
    {
        return [
            'available' => false,
            'status' => 'unavailable',
            'expires_at' => null,
            'poll_after_seconds' => self::POLL_AFTER_SECONDS,
            'verification_number' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function appPayload(WebLoginApproval $approval): array
    {
        return [
            'id' => (string) $approval->id,
            'status' => $this->effectiveStatus($approval),
            'verification_number' => (string) $approval->verification_number,
            'requested_at' => ApiDateTime::dateTime($approval->requested_at),
            'expires_at' => ApiDateTime::dateTime($approval->expires_at),
            'request_device' => (string) $approval->request_device,
            'request_ip' => (string) ($approval->request_ip ?? ''),
        ];
    }

    private function browserSessionHash(Request $request): string
    {
        if (! $request->hasSession()) {
            $this->notFound();
        }

        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new \LogicException('APP_KEY is required for web login approval hashing.');
        }

        return hash_hmac(
            'sha256',
            'web-login-approval-session|'.$request->session()->getId(),
            $appKey,
        );
    }

    private function requestDevice(Request $request): string
    {
        $userAgent = (string) $request->userAgent();
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'Chrome/') => 'Google Chrome',
            str_contains($userAgent, 'Firefox/') => 'Mozilla Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Onbekende browser',
        };
        $device = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'onbekend apparaat',
        };

        return mb_substr($browser.' op '.$device, 0, 160);
    }

    /** @return array{allowed: bool, retry_after_seconds: int} */
    private function claimPushAllowance(User $user): array
    {
        $subject = hash('sha256', (string) $user->id);
        $cooldownKey = 'web-login-approval-push:cooldown:'.$subject;
        $hourlyKey = 'web-login-approval-push:hour:'.$subject;

        try {
            $allowance = Cache::lock('web-login-approval-push:lock:'.$subject, 5)->block(
                1,
                function () use ($cooldownKey, $hourlyKey): array {
                    $cooldownLimited = RateLimiter::tooManyAttempts($cooldownKey, 1);
                    $hourlyLimited = RateLimiter::tooManyAttempts($hourlyKey, self::PUSHES_PER_HOUR);
                    if ($cooldownLimited || $hourlyLimited) {
                        return [
                            'allowed' => false,
                            'retry_after_seconds' => max(
                                1,
                                $cooldownLimited ? RateLimiter::availableIn($cooldownKey) : 0,
                                $hourlyLimited ? RateLimiter::availableIn($hourlyKey) : 0,
                            ),
                        ];
                    }

                    RateLimiter::hit($cooldownKey, self::PUSH_COOLDOWN_SECONDS);
                    RateLimiter::hit($hourlyKey, 3600);

                    return ['allowed' => true, 'retry_after_seconds' => 0];
                },
            );

            return is_array($allowance)
                ? $allowance
                : ['allowed' => false, 'retry_after_seconds' => self::PUSH_COOLDOWN_SECONDS];
        } catch (\Throwable $exception) {
            // Push approval is optional. Cache unavailability must never block
            // the password + TOTP/recovery-code authentication path.
            report($exception);

            return ['allowed' => false, 'retry_after_seconds' => self::PUSH_COOLDOWN_SECONDS];
        }
    }

    private function auditPushRateLimit(
        Request $request,
        User $user,
        string $source,
        int $retryAfterSeconds,
    ): void {
        $this->auditService->record('auth.web_login_approval_push_rate_limited', $user, $user, [
            'source' => $source,
            'retry_after_seconds' => max(1, $retryAfterSeconds),
            'cooldown_seconds' => self::PUSH_COOLDOWN_SECONDS,
            'hourly_maximum' => self::PUSHES_PER_HOUR,
        ], null, $request);
    }

    private function pushRateLimited(int $retryAfterSeconds): never
    {
        $response = ApiResponse::error(
            'login_approval_rate_limited',
            'Wait before sending another login approval request.',
            429,
        );
        $response->headers->set('Retry-After', (string) max(1, $retryAfterSeconds));

        throw new HttpResponseException($response);
    }

    private function notFound(): never
    {
        throw new HttpResponseException(ApiResponse::error('not_found', 'The requested resource was not found.', 404));
    }

    private function expired(): never
    {
        throw new HttpResponseException(ApiResponse::error(
            'login_approval_expired',
            'Het inlogverzoek is verlopen.',
            410,
        ));
    }

    private function handled(): never
    {
        throw new HttpResponseException(ApiResponse::error(
            'login_approval_handled',
            'Het inlogverzoek is al afgehandeld.',
            409,
        ));
    }
}
