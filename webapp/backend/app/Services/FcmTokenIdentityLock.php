<?php

namespace App\Services;

use App\Exceptions\TransientPushDeliveryException;
use App\Models\FcmToken;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class FcmTokenIdentityLock
{
    /**
     * Longer than both the 180 second push-worker timeout and the 240 second
     * Redis retry_after lease. Provider submission can therefore never outlive
     * the lock and overlap a redelivery or device re-registration.
     */
    public const LOCK_SECONDS = 300;

    public const WAIT_SECONDS = 12;

    public function __construct(
        private readonly int $lockSeconds = self::LOCK_SECONDS,
        private readonly int $waitSeconds = self::WAIT_SECONDS,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function synchronized(
        string $userId,
        string $deviceId,
        string $clientType,
        Closure $callback,
    ): mixed {
        return $this->synchronizedKeys(
            [
                self::userKey($userId),
                self::key($userId, $deviceId, $clientType),
            ],
            $callback,
        );
    }

    /**
     * @template TResult
     *
     * @param  list<string>  $userIds
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function synchronizedUsers(array $userIds, Closure $callback): mixed
    {
        return $this->synchronizedKeys(
            array_map(
                static fn (string $userId): string => self::userKey($userId),
                $userIds,
            ),
            $callback,
        );
    }

    /**
     * Registration may reuse a provider token that was previously associated
     * with another device identity. Acquire every known identity in a stable
     * order so neither the old nor the requested device can race a revocation
     * delivery.
     *
     * @template TResult
     *
     * @param  list<array{user_id: string, device_id: string, client_type: string}>  $identities
     * @param  Closure(): TResult  $callback
     * @param  list<array{platform: string, token_hash: string}>  $providerTokens
     * @param  list<string>  $userIds
     * @return TResult
     */
    public function synchronizedMany(
        array $identities,
        Closure $callback,
        array $providerTokens = [],
        array $userIds = [],
    ): mixed {
        $keys = [
            ...array_map(
                static fn (string $userId): string => self::userKey($userId),
                $userIds,
            ),
            ...array_map(
                static fn (array $identity): string => self::key(
                    $identity['user_id'],
                    $identity['device_id'],
                    $identity['client_type'],
                ),
                $identities,
            ),
            ...array_map(
                static fn (array $providerToken): string => self::providerTokenKey(
                    $providerToken['platform'],
                    $providerToken['token_hash'],
                ),
                $providerTokens,
            ),
        ];

        return $this->synchronizedKeys($keys, $callback);
    }

    public static function keyForToken(FcmToken $token): string
    {
        return self::key(
            (string) $token->user_id,
            (string) $token->device_id,
            (string) $token->client_type,
        );
    }

    public static function key(string $userId, string $deviceId, string $clientType): string
    {
        $identity = json_encode([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'client_type' => $clientType,
        ], JSON_THROW_ON_ERROR);

        return 'push-device-state:'.hash('sha256', $identity);
    }

    public static function userKey(string $userId): string
    {
        return 'push-user-state:'.hash('sha256', $userId);
    }

    public static function providerTokenKeyForToken(FcmToken $token): string
    {
        return self::providerTokenKey(
            (string) $token->platform,
            self::tokenHash($token),
        );
    }

    public static function providerTokenKey(string $platform, string $tokenHash): string
    {
        $providerIdentity = json_encode([
            'platform' => strtolower($platform),
            'token_hash' => strtolower($tokenHash),
        ], JSON_THROW_ON_ERROR);

        return 'push-provider-token:'.hash('sha256', $providerIdentity);
    }

    public static function tokenHash(FcmToken $token): string
    {
        $storedHash = strtolower(trim((string) $token->token_hash));

        return preg_match('/^[a-f0-9]{64}$/', $storedHash) === 1
            ? $storedHash
            : hash('sha256', (string) $token->token);
    }

    /**
     * @template TResult
     *
     * @param  list<string>  $keys
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function synchronizedKeys(array $keys, Closure $callback): mixed
    {
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        try {
            return $this->acquire($keys, 0, $callback);
        } catch (LockTimeoutException $exception) {
            throw TransientPushDeliveryException::forDeviceStateLock($exception);
        }
    }

    /**
     * @template TResult
     *
     * @param  list<string>  $keys
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function acquire(array $keys, int $offset, Closure $callback): mixed
    {
        $key = $keys[$offset] ?? null;
        if ($key === null) {
            return $callback();
        }

        return Cache::lock($key, $this->lockSeconds)
            ->block(
                $this->waitSeconds,
                fn (): mixed => $this->acquire($keys, $offset + 1, $callback),
            );
    }
}
