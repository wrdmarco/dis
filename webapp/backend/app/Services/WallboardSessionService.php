<?php

namespace App\Services;

use App\Models\Wallboard;
use App\Models\WallboardSession;
use App\Repositories\WallboardRepository;
use App\Support\ApiDateTime;
use DateTimeInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class WallboardSessionService
{
    public const COOKIE_NAME = '__Host-dis_wallboard';

    public function __construct(
        private readonly WallboardRepository $repository,
    ) {}

    /**
     * @return array{wallboard: Wallboard, session: WallboardSession, credential: string}
     */
    public function createFromPairing(
        Wallboard $wallboard,
        string $secret,
        ?string $deviceName,
        Request $request,
    ): array {
        $now = now();
        $session = $wallboard->sessions()->create([
            'token_hash' => $this->tokenHash($secret),
            'device_name' => $deviceName === null ? null : trim($deviceName),
            'ip_address' => mb_substr((string) $request->ip(), 0, 64),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'last_seen_at' => $now,
            'last_rotated_at' => $now,
            'expires_at' => $this->idleExpiresAt(),
        ]);
        $wallboard->forceFill(['last_seen_at' => $now])->save();

        return [
            'wallboard' => $wallboard,
            'session' => $session,
            'credential' => $this->credential($session, $secret),
        ];
    }

    public function reissuePairingCredential(
        WallboardSession $session,
        Wallboard $wallboard,
        string $secret,
    ): ?string {
        $now = now();
        if ((string) $session->wallboard_id !== (string) $wallboard->id
            || ! $wallboard->is_enabled
            || $session->revoked_at !== null
            || $this->absoluteExpiryReached($session, $now)
            || $this->atOrBefore($session->expires_at, $now)
            || ! hash_equals((string) $session->token_hash, $this->tokenHash($secret))) {
            return null;
        }

        return $this->credential($session, $secret);
    }

    /**
     * Authenticate and optionally rotate the credential. The new plaintext is
     * carried only on the request so middleware can set the replacement
     * HttpOnly cookie; it is never persisted or returned in JSON.
     */
    public function authenticate(Request $request): WallboardSession
    {
        [$sessionId, $secret] = $this->parseCredential((string) $request->cookie(self::COOKIE_NAME, ''));

        return DB::transaction(function () use ($request, $sessionId, $secret): WallboardSession {
            $session = $this->repository->lockSession($sessionId);
            $now = now();
            if ($session === null
                || $session->wallboard === null
                || ! $session->wallboard->is_enabled
                || $session->revoked_at !== null
                || $this->absoluteExpiryReached($session, $now)
                || $this->atOrBefore($session->expires_at, $now)) {
                throw new AuthenticationException('Invalid wallboard session.');
            }

            $candidateHash = $this->tokenHash($secret);
            $matchesCurrent = hash_equals((string) $session->token_hash, $candidateHash);
            $matchesPrevious = is_string($session->previous_token_hash)
                && $this->isAfter($session->previous_token_expires_at, $now)
                && hash_equals($session->previous_token_hash, $candidateHash);
            if (! $matchesCurrent && ! $matchesPrevious) {
                throw new AuthenticationException('Invalid wallboard session.');
            }

            if ($matchesCurrent && $this->rotationDue($session, $now)) {
                $newSecret = $this->newSecret();
                $session->forceFill([
                    'previous_token_hash' => $session->token_hash,
                    'previous_token_expires_at' => $now->copy()->addSeconds(max(
                        15,
                        (int) config('dis.wallboards.rotation_grace_seconds', 120),
                    )),
                    'token_hash' => $this->tokenHash($newSecret),
                    'last_rotated_at' => $now,
                ]);
                $request->attributes->set(
                    'wallboard.rotated_credential',
                    $this->credential($session, $newSecret),
                );
            }

            $touchBefore = ApiDateTime::comparableWallClock($now)->subSeconds(max(
                10,
                (int) config('dis.wallboards.touch_interval_seconds', 60),
            ));
            if ($this->heartbeatTouchDue($session, $touchBefore)) {
                $session->forceFill([
                    'last_seen_at' => $now,
                    'expires_at' => $this->idleExpiresAt($session),
                    'ip_address' => mb_substr((string) $request->ip(), 0, 64),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                ]);
                $session->wallboard->forceFill(['last_seen_at' => $now])->save();
            }

            if ($session->isDirty()) {
                $session->save();
            }

            $request->attributes->set('wallboard.session', $session);
            $request->attributes->set('wallboard', $session->wallboard);

            return $session;
        }, 3);
    }

    public function cookie(string $credential, WallboardSession $session): Cookie
    {
        return new Cookie(
            self::COOKIE_NAME,
            $credential,
            ApiDateTime::localWallClock($session->expires_at) ?? $session->expires_at,
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    public function clearCookie(): Cookie
    {
        return new Cookie(
            self::COOKIE_NAME,
            '',
            1,
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    private function rotationDue(WallboardSession $session, DateTimeInterface $now): bool
    {
        return $session->last_rotated_at === null
            || ApiDateTime::comparableWallClock($session->last_rotated_at)->lessThanOrEqualTo(
                ApiDateTime::comparableWallClock($now)->subHours(max(
                    1,
                    (int) config('dis.wallboards.rotation_hours', 12),
                )),
            );
    }

    private function idleExpiresAt(?WallboardSession $session = null): DateTimeInterface
    {
        $now = ApiDateTime::localWallClock(now()) ?? now();
        $createdAt = ApiDateTime::localWallClock($session?->created_at) ?? $now;
        $idleExpiry = $now->addDays(max(1, (int) config('dis.wallboards.session_idle_days', 30)));
        $absoluteExpiry = $createdAt->addDays(max(
            1,
            (int) config('dis.wallboards.session_absolute_days', 365),
        ));

        return $idleExpiry->lessThan($absoluteExpiry) ? $idleExpiry : $absoluteExpiry;
    }

    private function absoluteExpiryReached(WallboardSession $session, DateTimeInterface $now): bool
    {
        return $session->created_at === null
            || ApiDateTime::comparableWallClock($session->created_at)->addDays(max(
                1,
                (int) config('dis.wallboards.session_absolute_days', 365),
            ))->lessThanOrEqualTo(ApiDateTime::comparableWallClock($now));
    }

    private function atOrBefore(?DateTimeInterface $value, DateTimeInterface $boundary): bool
    {
        return $value === null
            || ApiDateTime::comparableWallClock($value)
                ->lessThanOrEqualTo(ApiDateTime::comparableWallClock($boundary));
    }

    private function isAfter(?DateTimeInterface $value, DateTimeInterface $boundary): bool
    {
        return $value !== null
            && ApiDateTime::comparableWallClock($value)
                ->isAfter(ApiDateTime::comparableWallClock($boundary));
    }

    private function heartbeatTouchDue(WallboardSession $session, DateTimeInterface $touchBefore): bool
    {
        return $session->last_seen_at === null
            || ApiDateTime::comparableWallClock($session->last_seen_at)
                ->lessThanOrEqualTo(ApiDateTime::comparableWallClock($touchBefore));
    }

    /** @return array{0: string, 1: string} */
    private function parseCredential(string $credential): array
    {
        $parts = explode('.', $credential, 2);
        if (count($parts) !== 2 || ! Str::isUlid($parts[0]) || strlen($parts[1]) < 43) {
            throw new AuthenticationException('Invalid wallboard session.');
        }

        return [$parts[0], $parts[1]];
    }

    private function credential(WallboardSession $session, string $secret): string
    {
        return (string) $session->id.'.'.$secret;
    }

    private function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function tokenHash(string $secret): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \LogicException('APP_KEY is required for wallboard credential hashing.');
        }

        return hash_hmac('sha256', $secret, $key);
    }
}
