<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPairingRequest;
use App\Models\WallboardSession;
use App\Repositories\WallboardPairingRequestRepository;
use App\Repositories\WallboardRepository;
use App\Support\ApiDateTime;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

final class WallboardPairingService
{
    public const COOKIE_NAME = '__Host-dis_wallboard_pairing';

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly WallboardPairingRequestRepository $pairingRequests,
        private readonly WallboardRepository $wallboards,
        private readonly WallboardSessionService $sessions,
        private readonly AuditService $auditService,
    ) {}

    /**
     * @return array{pairing_request: WallboardPairingRequest, credential: string, data: array<string, mixed>}
     */
    public function start(?string $deviceName, Request $request): array
    {
        $existing = $this->existingRequest($request);
        if ($existing !== null) {
            return $existing;
        }

        $trimmedDeviceName = $deviceName === null ? null : trim($deviceName);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $secret = $this->newSecret();
            $code = $this->displayCode($secret);
            $codeHash = $this->codeHash($code);
            if ($this->pairingRequests->codeHashExists($codeHash)) {
                continue;
            }

            $expiresAt = now()->addSeconds($this->pairingTtlSeconds());
            try {
                /** @var WallboardPairingRequest $pairingRequest */
                $pairingRequest = DB::transaction(function () use (
                    $codeHash,
                    $secret,
                    $trimmedDeviceName,
                    $expiresAt,
                    $request,
                ): WallboardPairingRequest {
                    $created = $this->pairingRequests->create([
                        'code_hash' => $codeHash,
                        'secret_hash' => $this->temporarySecretHash($secret),
                        'device_name' => $trimmedDeviceName,
                        'request_ip' => mb_substr((string) $request->ip(), 0, 64),
                        'request_user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                        'expires_at' => $expiresAt,
                    ]);
                    if (! $created instanceof WallboardPairingRequest) {
                        throw new \LogicException('Wallboard pairing repository returned an unexpected model.');
                    }

                    $this->auditService->record('wallboards.pairing_requested', $created, null, [
                        'expires_at' => ApiDateTime::dateTime($expiresAt),
                        'device_name' => $trimmedDeviceName,
                    ], null, $request);

                    return $created;
                }, 3);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    continue;
                }

                throw $exception;
            }

            return $this->startResult($pairingRequest, $secret, $code);
        }

        throw new \RuntimeException('Unable to allocate a unique wallboard pairing code.');
    }

    /** @return array{status: string, pairing_request_id: string, wallboard_id: string, expires_at: string|null} */
    public function approve(Wallboard $wallboard, string $code, User $actor, Request $request): array
    {
        $normalizedCode = $this->normalizeCode($code);
        if (! $this->validNormalizedCode($normalizedCode)) {
            $this->invalidCode();
        }

        return DB::transaction(function () use ($wallboard, $normalizedCode, $actor, $request): array {
            $lockedWallboard = $this->wallboards->lockWallboard((string) $wallboard->id);
            if (! $lockedWallboard->is_enabled) {
                throw ValidationException::withMessages([
                    'wallboard' => ['Een uitgeschakeld wallboard kan niet worden gekoppeld.'],
                ]);
            }

            $pairingRequest = $this->pairingRequests->lockByCodeHash($this->codeHash($normalizedCode));
            if ($pairingRequest === null
                || $pairingRequest->expires_at->lessThanOrEqualTo(now())
                || $pairingRequest->approved_at !== null
                || $pairingRequest->wallboard_id !== null
                || $pairingRequest->consumed_at !== null
                || $pairingRequest->wallboard_session_id !== null) {
                $this->invalidCode();
            }

            $approvedAt = now();
            $pairingRequest->forceFill([
                'wallboard_id' => $lockedWallboard->id,
                'approved_by' => $actor->id,
                'approved_at' => $approvedAt,
            ])->save();

            $this->auditService->record('wallboards.pairing_approved', $lockedWallboard, $actor, [
                'pairing_request_id' => (string) $pairingRequest->id,
                'expires_at' => ApiDateTime::dateTime($pairingRequest->expires_at),
            ], null, $request);

            return [
                'status' => 'approved',
                'pairing_request_id' => (string) $pairingRequest->id,
                'wallboard_id' => (string) $lockedWallboard->id,
                'expires_at' => ApiDateTime::dateTime($pairingRequest->expires_at),
            ];
        }, 3);
    }

    /**
     * @return array{data: array<string, mixed>, session?: WallboardSession, credential?: string}
     */
    public function status(Request $request): array
    {
        [$pairingRequestId, $temporarySecret] = $this->parseCredential(
            (string) $request->cookie(self::COOKIE_NAME, ''),
        );

        $phase = DB::transaction(function () use ($pairingRequestId, $temporarySecret): array {
            $pairingRequest = $this->pairingRequests->lockById($pairingRequestId);
            if (! $this->validRequestSecret($pairingRequest, $temporarySecret)
                || $pairingRequest->expires_at->lessThanOrEqualTo(now())) {
                throw new AuthenticationException('Invalid wallboard pairing request.');
            }

            if ($pairingRequest->approved_at === null && $pairingRequest->wallboard_id === null) {
                return ['data' => $this->publicStatus($pairingRequest)];
            }
            if ($pairingRequest->approved_at === null || $pairingRequest->wallboard_id === null) {
                throw new AuthenticationException('Invalid wallboard pairing request.');
            }

            return ['wallboard_id' => (string) $pairingRequest->wallboard_id];
        }, 3);

        if (isset($phase['data']) && is_array($phase['data'])) {
            return ['data' => $phase['data']];
        }
        $wallboardId = (string) ($phase['wallboard_id'] ?? '');
        if ($wallboardId === '') {
            throw new AuthenticationException('Invalid wallboard pairing request.');
        }

        // All operations that can mutate a paired wallboard acquire locks in
        // the same order: wallboard, pairing request, then session. This avoids
        // a cross-instance deadlock with disable/revoke/delete operations.
        return DB::transaction(function () use (
            $wallboardId,
            $pairingRequestId,
            $temporarySecret,
            $request,
        ): array {
            $wallboard = $this->wallboards->lockWallboardOrNull($wallboardId);
            if ($wallboard === null || ! $wallboard->is_enabled) {
                throw new AuthenticationException('Invalid wallboard pairing request.');
            }

            $pairingRequest = $this->pairingRequests->lockById($pairingRequestId);
            if (! $this->validRequestSecret($pairingRequest, $temporarySecret)
                || $pairingRequest->expires_at->lessThanOrEqualTo(now())
                || $pairingRequest->approved_at === null
                || (string) $pairingRequest->wallboard_id !== $wallboardId) {
                throw new AuthenticationException('Invalid wallboard pairing request.');
            }

            $permanentSecret = $this->permanentSessionSecret($temporarySecret);
            if ($pairingRequest->consumed_at !== null) {
                if ($pairingRequest->wallboard_session_id === null) {
                    throw new AuthenticationException('Invalid wallboard pairing request.');
                }
                $session = $this->wallboards->lockSession((string) $pairingRequest->wallboard_session_id);
                $credential = $session === null
                    ? null
                    : $this->sessions->reissuePairingCredential($session, $wallboard, $permanentSecret);
                if ($credential === null) {
                    throw new AuthenticationException('Invalid wallboard pairing request.');
                }

                return [
                    'data' => ['status' => 'paired'],
                    'session' => $session,
                    'credential' => $credential,
                ];
            }

            $now = now();
            $wallboard->sessions()->whereNull('revoked_at')->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);
            $created = $this->sessions->createFromPairing(
                $wallboard,
                $permanentSecret,
                $pairingRequest->device_name,
                $request,
            );
            $session = $created['session'];

            $pairingRequest->forceFill([
                'wallboard_session_id' => $session->id,
                'consumed_at' => $now,
                'consumed_ip' => mb_substr((string) $request->ip(), 0, 64),
                'consumed_user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            ])->save();
            $wallboard->forceFill([
                'paired_at' => $now,
                'last_seen_at' => $now,
            ])->save();

            $this->auditService->record('wallboards.paired', $wallboard, $pairingRequest->approver, [
                'pairing_request_id' => (string) $pairingRequest->id,
                'session_id' => (string) $session->id,
                'device_name' => $pairingRequest->device_name,
            ], null, $request);

            return [
                'data' => ['status' => 'paired'],
                'session' => $session,
                'credential' => $created['credential'],
            ];
        }, 3);
    }

    public function cookie(string $credential, WallboardPairingRequest $pairingRequest): Cookie
    {
        return new Cookie(
            self::COOKIE_NAME,
            $credential,
            $pairingRequest->expires_at,
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

    public function codeHash(string $code): string
    {
        return hash_hmac(
            'sha256',
            'wallboard-pairing-code|'.$this->normalizeCode($code),
            $this->appKey(),
        );
    }

    public function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /**
     * @return array{pairing_request: WallboardPairingRequest, credential: string, data: array<string, mixed>}|null
     */
    private function existingRequest(Request $request): ?array
    {
        try {
            [$pairingRequestId, $secret] = $this->parseCredential(
                (string) $request->cookie(self::COOKIE_NAME, ''),
            );
        } catch (AuthenticationException) {
            return null;
        }

        return DB::transaction(function () use ($pairingRequestId, $secret): ?array {
            $pairingRequest = $this->pairingRequests->lockById($pairingRequestId);
            if (! $this->validRequestSecret($pairingRequest, $secret)
                || $pairingRequest->expires_at->lessThanOrEqualTo(now())) {
                return null;
            }

            return $this->startResult($pairingRequest, $secret, $this->displayCode($secret));
        }, 3);
    }

    /**
     * @return array{pairing_request: WallboardPairingRequest, credential: string, data: array<string, mixed>}
     */
    private function startResult(
        WallboardPairingRequest $pairingRequest,
        string $secret,
        string $code,
    ): array {
        return [
            'pairing_request' => $pairingRequest,
            'credential' => $this->credential($pairingRequest, $secret),
            'data' => [
                'code' => $code,
                'status' => $pairingRequest->approved_at === null ? 'pending' : 'approved',
                'expires_at' => ApiDateTime::dateTime($pairingRequest->expires_at),
                'poll_after_seconds' => 2,
            ],
        ];
    }

    /** @return array{status: string, expires_at: string|null, poll_after_seconds: int} */
    private function publicStatus(WallboardPairingRequest $pairingRequest): array
    {
        return [
            'status' => 'pending',
            'expires_at' => ApiDateTime::dateTime($pairingRequest->expires_at),
            'poll_after_seconds' => 2,
        ];
    }

    private function validRequestSecret(?WallboardPairingRequest $pairingRequest, string $secret): bool
    {
        return $pairingRequest !== null
            && hash_equals((string) $pairingRequest->secret_hash, $this->temporarySecretHash($secret));
    }

    /** @return array{0: string, 1: string} */
    private function parseCredential(string $credential): array
    {
        $parts = explode('.', $credential, 2);
        if (count($parts) !== 2 || ! Str::isUlid($parts[0]) || strlen($parts[1]) !== 43) {
            throw new AuthenticationException('Invalid wallboard pairing request.');
        }

        return [$parts[0], $parts[1]];
    }

    private function credential(WallboardPairingRequest $pairingRequest, string $secret): string
    {
        return (string) $pairingRequest->id.'.'.$secret;
    }

    private function displayCode(string $secret): string
    {
        $bytes = hash_hmac('sha256', 'wallboard-pairing-display-code', $secret, true);
        $code = '';
        for ($index = 0; $index < 8; $index++) {
            $code .= self::CODE_ALPHABET[ord($bytes[$index]) & 31];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    private function temporarySecretHash(string $secret): string
    {
        return hash_hmac('sha256', 'wallboard-pairing-secret|'.$secret, $this->appKey());
    }

    private function permanentSessionSecret(string $temporarySecret): string
    {
        $bytes = hash_hmac(
            'sha256',
            'wallboard-session-from-pairing|'.$temporarySecret,
            $this->appKey(),
            true,
        );

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function pairingTtlSeconds(): int
    {
        return min(900, max(60, (int) config('dis.wallboards.pairing_ttl_seconds', 300)));
    }

    private function validNormalizedCode(string $code): bool
    {
        return preg_match('/\A[A-HJ-NP-Z2-9]{8}\z/', $code) === 1;
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages([
            'code' => ['De koppelcode is ongeldig, verlopen of al gebruikt.'],
        ]);
    }

    private function appKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \LogicException('APP_KEY is required for wallboard pairing hashing.');
        }

        return $key;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true)
            || (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
