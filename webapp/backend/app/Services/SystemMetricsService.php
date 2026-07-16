<?php

namespace App\Services;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Throwable;

final class SystemMetricsService
{
    private const CPU_SAMPLE_TTL_SECONDS = 30;

    public function __construct(
        private readonly Repository $cache,
        private readonly ?string $procRoot = null,
        private readonly ?string $diskPath = null,
        private readonly ?string $hostIdentity = null,
    ) {}

    /**
     * @return array{
     *     uptime_seconds: int|null,
     *     cpu: array{usage_percent: float|null, logical_processors: int|null, load_average_1m: float|null},
     *     memory: array{total_bytes: int|null, used_bytes: int|null, available_bytes: int|null, usage_percent: float|null},
     *     disk: array{label: string, total_bytes: int|null, used_bytes: int|null, available_bytes: int|null, usage_percent: float|null}
     * }
     */
    public function snapshot(): array
    {
        $cpuSample = $this->readCpuSample();

        return [
            'uptime_seconds' => $this->uptimeSeconds(),
            'cpu' => [
                'usage_percent' => $cpuSample === null ? null : $this->cpuUsagePercent($cpuSample),
                'logical_processors' => $cpuSample['logical_processors'] ?? null,
                'load_average_1m' => $this->loadAverageOneMinute(),
            ],
            'memory' => $this->memoryMetrics(),
            'disk' => $this->diskMetrics(),
        ];
    }

    public function uptimeSeconds(): ?int
    {
        $contents = $this->readProcFile('uptime');
        if ($contents === null || preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:\s|$)/', trim($contents), $matches) !== 1) {
            return null;
        }

        $seconds = (float) $matches[1];

        return is_finite($seconds) && $seconds >= 0 && $seconds <= PHP_INT_MAX
            ? (int) floor($seconds)
            : null;
    }

    /**
     * @return array{total: int, idle: int, logical_processors: int|null}|null
     */
    private function readCpuSample(): ?array
    {
        $contents = $this->readProcFile('stat');
        if ($contents === null) {
            return null;
        }

        $lines = preg_split('/\r?\n/', trim($contents));
        if (! is_array($lines) || $lines === []) {
            return null;
        }

        $aggregate = preg_split('/\s+/', trim($lines[0]));
        if (! is_array($aggregate) || ($aggregate[0] ?? null) !== 'cpu' || count($aggregate) < 5) {
            return null;
        }

        $counters = [];
        foreach (array_slice($aggregate, 1, 8) as $value) {
            if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
                return null;
            }

            $counter = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (! is_int($counter)) {
                return null;
            }

            $counters[] = $counter;
        }

        if (count($counters) < 4) {
            return null;
        }

        $total = array_sum($counters);
        $idle = $counters[3] + ($counters[4] ?? 0);
        if (! is_int($total) || $total <= 0 || $idle > $total) {
            return null;
        }

        $logicalProcessors = count(array_filter(
            $lines,
            static fn (string $line): bool => preg_match('/^cpu\d+\s/', $line) === 1,
        ));

        return [
            'total' => $total,
            'idle' => $idle,
            'logical_processors' => $logicalProcessors > 0 ? $logicalProcessors : null,
        ];
    }

    /**
     * @param  array{total: int, idle: int, logical_processors: int|null}  $current
     */
    private function cpuUsagePercent(array $current): ?float
    {
        $cacheKey = 'system-metrics:cpu:'.$this->hostCacheIdentity();

        try {
            $store = $this->cache->getStore();
            if ($store instanceof LockProvider) {
                $lock = $store->lock($cacheKey.':lock', 2);
                if (! $lock->get()) {
                    return null;
                }

                try {
                    return $this->recordCpuSampleAndCalculate($cacheKey, $current);
                } finally {
                    $lock->release();
                }
            }

            return $this->recordCpuSampleAndCalculate($cacheKey, $current);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{total: int, idle: int, logical_processors: int|null}  $current
     */
    private function recordCpuSampleAndCalculate(string $cacheKey, array $current): ?float
    {
        $previous = $this->cache->get($cacheKey);
        $this->cache->put($cacheKey, [
            'total' => $current['total'],
            'idle' => $current['idle'],
        ], self::CPU_SAMPLE_TTL_SECONDS);

        if (! is_array($previous)
            || ! is_int($previous['total'] ?? null)
            || ! is_int($previous['idle'] ?? null)) {
            return null;
        }

        $totalDelta = $current['total'] - $previous['total'];
        $idleDelta = $current['idle'] - $previous['idle'];
        if ($totalDelta <= 0 || $idleDelta < 0 || $idleDelta > $totalDelta) {
            return null;
        }

        return round(max(0, min(100, (($totalDelta - $idleDelta) / $totalDelta) * 100)), 1);
    }

    private function loadAverageOneMinute(): ?float
    {
        $contents = $this->readProcFile('loadavg');
        if ($contents === null || preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:\s|$)/', trim($contents), $matches) !== 1) {
            return null;
        }

        $load = (float) $matches[1];

        return is_finite($load) && $load >= 0 ? round($load, 2) : null;
    }

    /**
     * @return array{total_bytes: int|null, used_bytes: int|null, available_bytes: int|null, usage_percent: float|null}
     */
    private function memoryMetrics(): array
    {
        $unavailable = [
            'total_bytes' => null,
            'used_bytes' => null,
            'available_bytes' => null,
            'usage_percent' => null,
        ];
        $contents = $this->readProcFile('meminfo');
        if ($contents === null) {
            return $unavailable;
        }

        $values = [];
        foreach (preg_split('/\r?\n/', trim($contents)) ?: [] as $line) {
            if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB$/', trim($line), $matches) === 1) {
                $kilobytes = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if (is_int($kilobytes) && $kilobytes <= intdiv(PHP_INT_MAX, 1024)) {
                    $values[$matches[1]] = $kilobytes * 1024;
                }
            }
        }

        $total = $values['MemTotal'] ?? null;
        $available = $values['MemAvailable'] ?? null;
        if (! is_int($total) || $total <= 0 || ! is_int($available) || $available < 0 || $available > $total) {
            return $unavailable;
        }

        $used = $total - $available;

        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'available_bytes' => $available,
            'usage_percent' => round(($used / $total) * 100, 1),
        ];
    }

    /**
     * @return array{label: string, total_bytes: int|null, used_bytes: int|null, available_bytes: int|null, usage_percent: float|null}
     */
    private function diskMetrics(): array
    {
        $unavailable = [
            'label' => 'DIS data',
            'total_bytes' => null,
            'used_bytes' => null,
            'available_bytes' => null,
            'usage_percent' => null,
        ];
        $path = $this->resolvedDiskPath();
        if ($path === null) {
            return $unavailable;
        }

        $total = @disk_total_space($path);
        $available = @disk_free_space($path);
        if (! is_float($total) || ! is_float($available) || ! is_finite($total) || ! is_finite($available)
            || $total <= 0 || $available < 0 || $available > $total || $total > PHP_INT_MAX) {
            return $unavailable;
        }

        $totalBytes = (int) floor($total);
        $availableBytes = (int) floor($available);
        $usedBytes = $totalBytes - $availableBytes;

        return [
            'label' => 'DIS data',
            'total_bytes' => $totalBytes,
            'used_bytes' => $usedBytes,
            'available_bytes' => $availableBytes,
            'usage_percent' => round(($usedBytes / $totalBytes) * 100, 1),
        ];
    }

    private function resolvedDiskPath(): ?string
    {
        $configuredPath = trim($this->diskPath ?? (string) config('dis.system_metrics.disk_path', ''));
        if ($configuredPath !== '') {
            return $this->isAbsoluteLocalPath($configuredPath) && is_dir($configuredPath)
                ? $configuredPath
                : null;
        }

        $storagePath = storage_path();

        return $this->isAbsoluteLocalPath($storagePath) && is_dir($storagePath) ? $storagePath : null;
    }

    private function isAbsoluteLocalPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            return false;
        }

        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function hostCacheIdentity(): string
    {
        $identity = trim($this->hostIdentity ?? ((string) gethostname()));

        return hash('sha256', $identity !== '' ? $identity : PHP_OS_FAMILY);
    }

    private function readProcFile(string $filename): ?string
    {
        if (! in_array($filename, ['loadavg', 'meminfo', 'stat', 'uptime'], true)) {
            return null;
        }

        $root = rtrim($this->procRoot ?? '/proc', '/\\');
        if ($root === '' || str_contains($root, "\0") || str_contains($root, '://')) {
            return null;
        }

        $contents = @file_get_contents($root.DIRECTORY_SEPARATOR.$filename, false, null, 0, 1024 * 1024);

        return is_string($contents) ? $contents : null;
    }
}
