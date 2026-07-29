<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class KnmiRadarWmsThrottle
{
    private const CACHE_KEY = 'operational-radar:knmi:wms-throttle:v1';

    private const STATE_TTL_SECONDS = 3600;

    public function __construct(private readonly KnmiRadarConfiguration $configuration) {}

    /**
     * Serialize all anonymous KNMI WMS traffic across PHP processes. KNMI
     * documents a one-request-per-second anonymous IP limit, so capabilities
     * and GetMap requests share this installation-wide gate.
     *
     * @template T
     *
     * @param  callable(): T  $resolver
     * @return T
     */
    public function request(callable $resolver): mixed
    {
        try {
            return Cache::lock(
                self::CACHE_KEY.':lock',
                $this->configuration->upstreamThrottleLockSeconds(),
            )->block(
                $this->configuration->upstreamThrottleWaitSeconds(),
                function () use ($resolver): mixed {
                    $nowMilliseconds = $this->nowMilliseconds();
                    $previous = $this->timestampFromCache(
                        Cache::get(self::CACHE_KEY.':last-start-ms'),
                    );
                    if ($previous !== null && $previous > $nowMilliseconds + 5_000) {
                        throw new \RuntimeException('The KNMI WMS throttle clock is invalid.');
                    }

                    $jitter = random_int(0, $this->configuration->upstreamJitterMilliseconds());
                    $earliest = $previous !== null
                        ? $previous + $this->configuration->upstreamMinimumIntervalMilliseconds() + $jitter
                        : $nowMilliseconds;
                    $waitMilliseconds = max(0, $earliest - $nowMilliseconds);
                    if ($waitMilliseconds > 5_000) {
                        throw new \RuntimeException('The KNMI WMS throttle wait is invalid.');
                    }
                    if ($waitMilliseconds > 0) {
                        usleep($waitMilliseconds * 1_000);
                    }

                    $startedAt = $this->nowMilliseconds();
                    if (Cache::put(
                        self::CACHE_KEY.':last-start-ms',
                        $startedAt,
                        self::STATE_TTL_SECONDS,
                    ) === false) {
                        throw new \RuntimeException('The KNMI WMS throttle state could not be stored.');
                    }

                    return $resolver();
                },
            );
        } catch (LockTimeoutException $exception) {
            throw new \RuntimeException('The KNMI WMS throttle is busy.', 0, $exception);
        } catch (Throwable $exception) {
            if ($exception instanceof \RuntimeException) {
                throw $exception;
            }

            throw new \RuntimeException('The KNMI WMS throttle is unavailable.', 0, $exception);
        }
    }

    private function nowMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1_000);
    }

    private function timestampFromCache(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value)
            && strlen($value) <= 16
            && ctype_digit($value)
            && (int) $value > 0) {
            return (int) $value;
        }

        throw new \RuntimeException('The KNMI WMS throttle state is invalid.');
    }
}
