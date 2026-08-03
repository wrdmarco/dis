<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

final class SystemDataUsageService
{
    private const SNAPSHOT_VERSION = 1;

    private const MAX_SNAPSHOT_BYTES = 65_536;

    private const DEFAULT_STALE_AFTER_SECONDS = 10_800;

    private const MAX_FUTURE_SKEW_SECONDS = 300;

    /**
     * Only deployment-managed top-level directories may cross the API boundary.
     * Unknown snapshot keys are deliberately ignored rather than reflected.
     *
     * @var array<string, array{label: string, description: string}>
     */
    private const DIRECTORY_CATALOG = [
        'backup' => [
            'label' => 'backup',
            'description' => 'Lokale back-upgegevens.',
        ],
        'backup-imports' => [
            'label' => 'backup-imports',
            'description' => 'Tijdelijke invoer voor back-upherstel.',
        ],
        'backup-requests' => [
            'label' => 'backup-requests',
            'description' => 'Afgeschermde aanvragen voor back-up- en herstelacties.',
        ],
        'backup-request-work' => [
            'label' => 'backup-request-work',
            'description' => 'Tijdelijke werkruimte voor back-up- en herstelacties.',
        ],
        'legacy-backup-state' => [
            'label' => 'legacy-backup-state',
            'description' => 'Bewaarde status uit oudere back-upversies.',
        ],
        'osrm' => [
            'label' => 'osrm',
            'description' => 'Lokale kaart- en routeringsgegevens.',
        ],
        'osrm-admin' => [
            'label' => 'osrm-admin',
            'description' => 'Beheerstatus voor lokale routeringsgegevens.',
        ],
        'playwright-browsers' => [
            'label' => 'playwright-browsers',
            'description' => 'Lokale browserruntime voor systeemcontroles.',
        ],
        'storage' => [
            'label' => 'storage',
            'description' => 'Algemene blijvende applicatieopslag.',
        ],
        'webapp' => [
            'label' => 'webapp',
            'description' => 'Blijvende opslag van de webapplicatie.',
        ],
    ];

    public function __construct(
        private readonly ?string $snapshotPath = null,
        private readonly ?int $staleAfterSeconds = null,
        private readonly ?bool $requireRootOwner = null,
    ) {}

    /**
     * @return array{
     *     generated_at: string|null,
     *     stale: bool,
     *     directories: list<array{name: string, label: string, description: string, size_bytes: int}>
     * }
     */
    public function snapshot(): array
    {
        $contents = $this->secureSnapshotContents();
        if ($contents === null) {
            return $this->unavailable();
        }

        try {
            $decoded = json_decode(
                $contents,
                true,
                4,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (Throwable) {
            return $this->unavailable();
        }

        if (! is_array($decoded)
            || ($decoded['version'] ?? null) !== self::SNAPSHOT_VERSION
            || ! is_array($decoded['directories'] ?? null)
            || (($decoded['directories'] ?? []) !== [] && array_is_list($decoded['directories']))) {
            return $this->unavailable();
        }

        $generatedAt = $this->timestamp($decoded['generated_at'] ?? null);
        if ($generatedAt === null) {
            return $this->unavailable();
        }

        $directories = [];
        foreach (self::DIRECTORY_CATALOG as $name => $presentation) {
            $size = $decoded['directories'][$name] ?? null;
            if (! is_int($size) || $size < 0) {
                continue;
            }

            $directories[] = [
                'name' => $name,
                'label' => $presentation['label'],
                'description' => $presentation['description'],
                'size_bytes' => $size,
            ];
        }

        usort($directories, static function (array $left, array $right): int {
            $sizeComparison = $right['size_bytes'] <=> $left['size_bytes'];

            return $sizeComparison !== 0 ? $sizeComparison : strcmp($left['name'], $right['name']);
        });

        return [
            'generated_at' => $generatedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'stale' => $this->isStale($generatedAt),
            'directories' => $directories,
        ];
    }

    /**
     * @return array{generated_at: null, stale: true, directories: array{}}
     */
    private function unavailable(): array
    {
        return [
            'generated_at' => null,
            'stale' => true,
            'directories' => [],
        ];
    }

    private function secureSnapshotContents(): ?string
    {
        $path = $this->configuredSnapshotPath();
        if ($path === null) {
            return null;
        }

        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        $before = @lstat($path);
        if (! is_array($before) || $this->unsafeSnapshotStat($before) || is_link($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $opened = fstat($handle);
            $after = @lstat($path);
            $realPath = realpath($path);
            $realParent = realpath(dirname($path));
            if (! is_array($opened)
                || ! is_array($after)
                || $this->unsafeSnapshotStat($opened)
                || $this->unsafeSnapshotStat($after)
                || is_link($path)
                || $realPath === false
                || $realParent === false
                || ! $this->samePath($realPath, $realParent.DIRECTORY_SEPARATOR.basename($path))
                || ! $this->sameFile($before, $opened)
                || ! $this->sameFile($opened, $after)) {
                return null;
            }

            $contents = stream_get_contents($handle, self::MAX_SNAPSHOT_BYTES + 1);
            if (! is_string($contents)
                || strlen($contents) !== (int) ($opened['size'] ?? $opened[7] ?? -1)) {
                return null;
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    private function configuredSnapshotPath(): ?string
    {
        $configured = trim($this->snapshotPath ?? (string) config(
            'dis.system_metrics.data_usage.snapshot_path',
            '/var/lib/dis-system-metrics/storage-usage.json',
        ));
        if ($configured === ''
            || str_contains($configured, "\0")
            || str_contains($configured, '://')
            || ! $this->isAbsoluteLocalPath($configured)) {
            return null;
        }

        $segments = explode('/', str_replace('\\', '/', $configured));
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        return $configured;
    }

    /** @param array<string|int, mixed> $stat */
    private function unsafeSnapshotStat(array $stat): bool
    {
        $mode = (int) ($stat['mode'] ?? $stat[2] ?? 0);
        $links = (int) ($stat['nlink'] ?? $stat[3] ?? 0);
        $owner = (int) ($stat['uid'] ?? $stat[4] ?? -1);
        $size = (int) ($stat['size'] ?? $stat[7] ?? -1);

        return ($mode & 0170000) !== 0100000
            || $links !== 1
            || $size < 1
            || $size > self::MAX_SNAPSHOT_BYTES
            || (PHP_OS_FAMILY !== 'Windows' && ($mode & 0022) !== 0)
            || ($this->rootOwnershipRequired() && $owner !== 0);
    }

    private function rootOwnershipRequired(): bool
    {
        return $this->requireRootOwner ?? app()->environment('production');
    }

    /**
     * @param  array<string|int, mixed>  $left
     * @param  array<string|int, mixed>  $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return (int) ($left['dev'] ?? $left[0] ?? -1) === (int) ($right['dev'] ?? $right[0] ?? -2)
            && (int) ($left['ino'] ?? $left[1] ?? -1) === (int) ($right['ino'] ?? $right[1] ?? -2);
    }

    private function samePath(string $left, string $right): bool
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($left, $right) === 0
            : hash_equals($left, $right);
    }

    private function isAbsoluteLocalPath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)
            || strlen($value) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function isStale(CarbonImmutable $generatedAt): bool
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $generated = $generatedAt->getTimestamp();
        if ($generated > $now + self::MAX_FUTURE_SKEW_SECONDS) {
            return true;
        }

        $configured = $this->staleAfterSeconds ?? (int) config(
            'dis.system_metrics.data_usage.stale_after_seconds',
            self::DEFAULT_STALE_AFTER_SECONDS,
        );
        $staleAfter = $configured >= 60 && $configured <= 604_800
            ? $configured
            : self::DEFAULT_STALE_AFTER_SECONDS;

        return $now - $generated > $staleAfter;
    }
}
