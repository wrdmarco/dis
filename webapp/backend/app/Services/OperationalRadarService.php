<?php

namespace App\Services;

use App\Contracts\OperationalRadarProvider;
use App\Support\OperationalRadarContent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class OperationalRadarService implements OperationalRadarProvider
{
    private const FRAME_PATTERN = '/\A(?<valid>\d{8}T\d{6}Z)-(?<context>o|f\d{8}T\d{6}Z)-(?<digest>[a-f0-9]{16})\z/D';

    private const CACHE_NAMESPACE = 'operational-radar:live:v1';

    public function __construct(
        private readonly DwdRadarConfiguration $dwdConfiguration,
        private readonly DwdRadarWmsClient $dwd,
        private readonly EumetsatLightningConfiguration $lightningConfiguration,
        private readonly EumetsatLightningWmsClient $lightning,
    ) {}

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'precipitation' => $this->precipitationMetadata(),
            'lightning' => $this->lightningMetadata(),
        ];
    }

    public function file(string $kind, string $snapshotId): ?OperationalRadarContent
    {
        if (preg_match(self::FRAME_PATTERN, $snapshotId) !== 1) {
            return null;
        }

        try {
            return match ($kind) {
                'precipitation' => $this->precipitationFrame($snapshotId),
                'lightning' => $this->lightningFrame($snapshotId),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function precipitationMetadata(): array
    {
        $source = $this->dwdConfiguration->source();
        try {
            $timeline = $this->dwdTimeline();
            if ($timeline === null) {
                return $this->unavailableLayer(
                    'precipitation',
                    $this->dwdConfiguration->frameWidth(),
                    $this->dwdConfiguration->frameHeight(),
                    $source,
                    'De live DWD-neerslagradar is tijdelijk niet bereikbaar.',
                );
            }
            $reference = $this->timestamp($timeline['reference_time'] ?? null);
            $refreshedAt = $this->timestamp($timeline['fetched_at'] ?? null);
            $rawFrames = $timeline['frames'] ?? null;
            if ($reference === null || $refreshedAt === null || ! is_array($rawFrames)) {
                throw new \UnexpectedValueException('The cached DWD radar timeline is invalid.');
            }

            $now = CarbonImmutable::now()->utc();
            $ageSeconds = max(0, (int) $reference->diffInSeconds($now, false));
            if ($ageSeconds > $this->dwdConfiguration->maximumFallbackAgeSeconds()) {
                return $this->unavailableLayer(
                    'precipitation',
                    $this->dwdConfiguration->frameWidth(),
                    $this->dwdConfiguration->frameHeight(),
                    $source,
                    'De laatste DWD-radarreferentie is ouder dan één uur en wordt niet meer getoond.',
                    $reference,
                    $refreshedAt,
                );
            }

            $frames = [];
            foreach (array_values($rawFrames) as $index => $frame) {
                if (! is_array($frame)) {
                    throw new \UnexpectedValueException('A DWD radar frame is invalid.');
                }
                $validAt = $this->timestamp($frame['valid_at'] ?? null);
                $phase = $frame['phase'] ?? null;
                if ($validAt === null || ! in_array($phase, ['observation', 'forecast'], true)) {
                    throw new \UnexpectedValueException('A DWD radar frame timestamp is invalid.');
                }
                $leadMinutes = (int) ($reference->diffInSeconds($validAt, false) / 60);
                if (($phase === 'observation' && $leadMinutes > 0)
                    || ($phase === 'forecast' && $leadMinutes <= 0)) {
                    throw new \UnexpectedValueException('A DWD radar frame phase is inconsistent.');
                }
                $token = $this->frameToken(
                    'precipitation',
                    $validAt,
                    $phase === 'forecast' ? $reference : null,
                    $phase,
                );
                $frames[] = [
                    'index' => $index,
                    'valid_at' => $validAt->toIso8601String(),
                    'lead_minutes' => $leadMinutes,
                    'phase' => $phase,
                    'image_url' => route('operational-weather.radar-atlas', [
                        'kind' => 'precipitation',
                        'snapshot' => $token,
                    ], false),
                ];
            }
            $expected = intdiv(
                $this->dwdConfiguration->historyMinutes() + $this->dwdConfiguration->forecastMinutes(),
                $this->dwdConfiguration->intervalMinutes(),
            ) + 1;
            if (count($frames) !== $expected) {
                throw new \UnexpectedValueException('The DWD radar timeline is incomplete.');
            }

            $stale = $ageSeconds > $this->dwdConfiguration->maximumAgeSeconds();

            return $this->publicLayer(
                kind: 'precipitation',
                status: $stale ? 'stale' : 'available',
                referenceTime: $reference,
                observedPeriodEnd: $reference,
                refreshedAt: $refreshedAt,
                frameWidth: $this->dwdConfiguration->frameWidth(),
                frameHeight: $this->dwdConfiguration->frameHeight(),
                frames: $frames,
                source: $source,
                availabilityNote: $stale
                    ? 'De live DWD-radar is ouder dan twintig minuten; het laatste beeld blijft tijdelijk zichtbaar.'
                    : null,
            );
        } catch (Throwable) {
            return $this->unavailableLayer(
                'precipitation',
                $this->dwdConfiguration->frameWidth(),
                $this->dwdConfiguration->frameHeight(),
                $source,
                'De live DWD-neerslagradar kon niet veilig worden gelezen.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function lightningMetadata(): array
    {
        $configuredSource = $this->lightningConfiguration->source();
        $license = $this->lightningConfiguration->license();
        $source = [
            'name' => $configuredSource['name'],
            'url' => $configuredSource['url'],
            'license' => $license['name'],
            'license_url' => $license['url'],
            'attribution' => 'Contains modified EUMETSAT Meteosat data '.CarbonImmutable::now()->utc()->year,
            'modified' => true,
            'processed_by' => 'DIS',
        ];
        try {
            $timeline = $this->lightningTimeline();
            if ($timeline === null) {
                return $this->unavailableLayer(
                    'lightning',
                    $this->lightningConfiguration->frameWidth(),
                    $this->lightningConfiguration->frameHeight(),
                    $source,
                    'De live EUMETSAT-bliksemradar is tijdelijk niet bereikbaar.',
                );
            }
            $reference = $this->timestamp($timeline['reference_time'] ?? null);
            $refreshedAt = $this->timestamp($timeline['fetched_at'] ?? null);
            $rawFrames = $timeline['frames'] ?? null;
            if ($reference === null || $refreshedAt === null || ! is_array($rawFrames)) {
                throw new \UnexpectedValueException('The cached EUMETSAT timeline is invalid.');
            }
            $source['attribution'] = 'Contains modified EUMETSAT Meteosat data '.$reference->year;
            $periodEnd = $reference->addMinutes($this->lightningConfiguration->intervalMinutes());
            $now = CarbonImmutable::now()->utc();
            $ageSeconds = max(0, (int) $periodEnd->diffInSeconds($now, false));
            if ($ageSeconds > $this->lightningConfiguration->maximumFallbackAgeSeconds()) {
                return $this->unavailableLayer(
                    'lightning',
                    $this->lightningConfiguration->frameWidth(),
                    $this->lightningConfiguration->frameHeight(),
                    $source,
                    'De laatste EUMETSAT-waarnemingsperiode is ouder dan één uur en wordt niet meer getoond.',
                    $reference,
                    $refreshedAt,
                    $periodEnd,
                );
            }

            $frames = [];
            foreach (array_values($rawFrames) as $index => $rawFrame) {
                $validAt = $this->timestamp($rawFrame);
                if ($validAt === null || $validAt->greaterThan($reference)) {
                    throw new \UnexpectedValueException('An EUMETSAT lightning timestamp is invalid.');
                }
                $leadMinutes = (int) ($reference->diffInSeconds($validAt, false) / 60);
                $token = $this->frameToken('lightning', $validAt, null, 'observation');
                $frames[] = [
                    'index' => $index,
                    'valid_at' => $validAt->toIso8601String(),
                    'lead_minutes' => $leadMinutes,
                    'phase' => 'observation',
                    'image_url' => route('operational-weather.radar-atlas', [
                        'kind' => 'lightning',
                        'snapshot' => $token,
                    ], false),
                ];
            }
            if (count($frames) !== $this->lightningConfiguration->frameCount()) {
                throw new \UnexpectedValueException('The EUMETSAT lightning timeline is incomplete.');
            }

            $stale = $ageSeconds > $this->lightningConfiguration->maximumAgeSeconds();

            return $this->publicLayer(
                kind: 'lightning',
                status: $stale ? 'stale' : 'available',
                referenceTime: $reference,
                observedPeriodEnd: $periodEnd,
                refreshedAt: $refreshedAt,
                frameWidth: $this->lightningConfiguration->frameWidth(),
                frameHeight: $this->lightningConfiguration->frameHeight(),
                frames: $frames,
                source: $source,
                availabilityNote: $stale
                    ? 'De live EUMETSAT-waarnemingen zijn ouder dan dertig minuten; het laatste beeld blijft tijdelijk zichtbaar.'
                    : null,
            );
        } catch (Throwable) {
            return $this->unavailableLayer(
                'lightning',
                $this->lightningConfiguration->frameWidth(),
                $this->lightningConfiguration->frameHeight(),
                $source,
                'De live EUMETSAT-bliksemradar kon niet veilig worden gelezen.',
            );
        }
    }

    private function precipitationFrame(string $token): ?OperationalRadarContent
    {
        $descriptor = $this->frameDescriptor('precipitation', $token);
        if ($descriptor === null) {
            return null;
        }

        return $this->cachedFrame(
            'precipitation',
            $token,
            $this->dwdConfiguration->frameCacheSeconds(),
            $this->dwdConfiguration->frameLockSeconds(),
            fn (): string => $this->dwd->frame(
                $descriptor['valid_at'],
                $descriptor['reference_time'],
                $descriptor['phase'],
            ),
        );
    }

    private function lightningFrame(string $token): ?OperationalRadarContent
    {
        $descriptor = $this->frameDescriptor('lightning', $token);
        if ($descriptor === null) {
            return null;
        }

        return $this->cachedFrame(
            'lightning',
            $token,
            $this->lightningConfiguration->frameCacheSeconds(),
            $this->lightningConfiguration->frameLockSeconds(),
            fn (): string => $this->lightning->frame($descriptor['valid_at']),
        );
    }

    /** @return array<string, mixed>|null */
    private function dwdTimeline(): ?array
    {
        return $this->cachedTimeline(
            'precipitation',
            $this->dwdConfiguration->timelineCacheSeconds(),
            $this->dwdConfiguration->maximumFallbackAgeSeconds(),
            $this->dwdConfiguration->timelineLockSeconds(),
            fn (): array => $this->dwd->timeline(),
        );
    }

    /** @return array<string, mixed>|null */
    private function lightningTimeline(): ?array
    {
        return $this->cachedTimeline(
            'lightning',
            240,
            $this->lightningConfiguration->maximumFallbackAgeSeconds(),
            $this->lightningConfiguration->timelineLockSeconds(),
            function (): array {
                $times = $this->lightning->latestFrameTimes();
                if (count($times) !== $this->lightningConfiguration->frameCount()) {
                    throw new \UnexpectedValueException('The EUMETSAT lightning timeline is incomplete.');
                }

                return [
                    'reference_time' => end($times)->toIso8601String(),
                    'frames' => array_map(
                        static fn (CarbonImmutable $time): string => $time->toIso8601String(),
                        $times,
                    ),
                ];
            },
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $resolver
     * @return array<string, mixed>|null
     */
    private function cachedTimeline(
        string $kind,
        int $freshSeconds,
        int $fallbackSeconds,
        int $lockSeconds,
        callable $resolver,
    ): ?array {
        $freshKey = self::CACHE_NAMESPACE.':timeline:'.$kind;
        $fallbackKey = $freshKey.':fallback';
        $resolverAttempted = false;
        try {
            $cached = Cache::get($freshKey);
            if (is_array($cached)) {
                return $cached;
            }

            return Cache::lock($freshKey.':lock', $lockSeconds)->block(5, function () use (
                $freshKey,
                $fallbackKey,
                $freshSeconds,
                $fallbackSeconds,
                $resolver,
                &$resolverAttempted,
            ): array {
                $cached = Cache::get($freshKey);
                if (is_array($cached)) {
                    return $cached;
                }
                $resolverAttempted = true;
                $timeline = [
                    ...$resolver(),
                    'fetched_at' => CarbonImmutable::now()->utc()->toIso8601String(),
                ];
                Cache::put($freshKey, $timeline, $freshSeconds);
                Cache::put($fallbackKey, $timeline, $fallbackSeconds);

                return $timeline;
            });
        } catch (LockTimeoutException) {
            try {
                $fresh = Cache::get($freshKey);
                if (is_array($fresh)) {
                    return $fresh;
                }
                $fallback = Cache::get($fallbackKey);

                return is_array($fallback) ? $fallback : null;
            } catch (Throwable) {
                return null;
            }
        } catch (Throwable) {
            try {
                $fallback = Cache::get($fallbackKey);
                if (is_array($fallback)) {
                    return $fallback;
                }
            } catch (Throwable) {
                // A cache outage must not turn the fixed WMS sources into an
                // unbounded request primitive; perform at most one direct try.
            }
            if (! $resolverAttempted) {
                try {
                    return [
                        ...$resolver(),
                        'fetched_at' => CarbonImmutable::now()->utc()->toIso8601String(),
                    ];
                } catch (Throwable) {
                    return null;
                }
            }

            return null;
        }
    }

    /**
     * @param  callable(): string  $resolver
     */
    private function cachedFrame(
        string $kind,
        string $token,
        int $cacheSeconds,
        int $lockSeconds,
        callable $resolver,
    ): ?OperationalRadarContent {
        $key = self::CACHE_NAMESPACE.':frame:'.$kind.':'.$token;
        $resolverAttempted = false;
        try {
            $cached = $this->contentFromCache(Cache::get($key));
            if ($cached !== null) {
                return $cached;
            }

            return Cache::lock($key.':lock', $lockSeconds)->block(5, function () use (
                $key,
                $cacheSeconds,
                $resolver,
                &$resolverAttempted,
            ): OperationalRadarContent {
                $cached = $this->contentFromCache(Cache::get($key));
                if ($cached !== null) {
                    return $cached;
                }
                $resolverAttempted = true;
                $body = $resolver();
                $content = OperationalRadarContent::fromBody($body);
                Cache::put($key, [
                    'body' => base64_encode($body),
                    'byte_size' => $content->byteSize,
                    'sha256' => $content->sha256,
                ], $cacheSeconds);

                return $content;
            });
        } catch (LockTimeoutException) {
            try {
                return $this->contentFromCache(Cache::get($key));
            } catch (Throwable) {
                return null;
            }
        } catch (Throwable) {
            if (! $resolverAttempted) {
                try {
                    return OperationalRadarContent::fromBody($resolver());
                } catch (Throwable) {
                    return null;
                }
            }

            return null;
        }
    }

    private function contentFromCache(mixed $value): ?OperationalRadarContent
    {
        if (! is_array($value)
            || ! is_string($value['body'] ?? null)
            || ! is_int($value['byte_size'] ?? null)
            || ! is_string($value['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $value['sha256']) !== 1) {
            return null;
        }
        $body = base64_decode($value['body'], true);
        if (! is_string($body)
            || strlen($body) !== $value['byte_size']
            || ! hash_equals($value['sha256'], hash('sha256', $body))) {
            return null;
        }

        return new OperationalRadarContent($body, strlen($body), $value['sha256']);
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @param  array<string, string|bool>  $source
     * @return array<string, mixed>
     */
    private function publicLayer(
        string $kind,
        string $status,
        CarbonImmutable $referenceTime,
        CarbonImmutable $observedPeriodEnd,
        CarbonImmutable $refreshedAt,
        int $frameWidth,
        int $frameHeight,
        array $frames,
        array $source,
        ?string $availabilityNote,
    ): array {
        $now = CarbonImmutable::now()->utc();

        return [
            'kind' => $kind,
            'status' => $status,
            'render_mode' => 'image_frames',
            'reference_time' => $referenceTime->toIso8601String(),
            'observed_period_end' => $observedPeriodEnd->toIso8601String(),
            'age_seconds' => max(0, (int) $observedPeriodEnd->diffInSeconds($now, false)),
            'lag_seconds' => max(0, (int) $observedPeriodEnd->diffInSeconds($refreshedAt, false)),
            'refreshed_at' => $refreshedAt->toIso8601String(),
            'bounds' => $this->bounds(),
            'atlas_url' => null,
            'atlas_columns' => 0,
            'atlas_rows' => 0,
            'frame_width' => $frameWidth,
            'frame_height' => $frameHeight,
            'frames' => $frames,
            'source' => $source,
            'availability_note' => $availabilityNote,
        ];
    }

    /**
     * @param  array<string, string|bool>  $source
     * @return array<string, mixed>
     */
    private function unavailableLayer(
        string $kind,
        int $frameWidth,
        int $frameHeight,
        array $source,
        string $note,
        ?CarbonImmutable $referenceTime = null,
        ?CarbonImmutable $refreshedAt = null,
        ?CarbonImmutable $observedPeriodEnd = null,
    ): array {
        $now = CarbonImmutable::now()->utc();
        $observedPeriodEnd ??= $referenceTime;

        return [
            'kind' => $kind,
            'status' => 'unavailable',
            'render_mode' => 'image_frames',
            'reference_time' => $referenceTime?->toIso8601String(),
            'observed_period_end' => $observedPeriodEnd?->toIso8601String(),
            'age_seconds' => $observedPeriodEnd === null
                ? null
                : max(0, (int) $observedPeriodEnd->diffInSeconds($now, false)),
            'lag_seconds' => $observedPeriodEnd === null || $refreshedAt === null
                ? null
                : max(0, (int) $observedPeriodEnd->diffInSeconds($refreshedAt, false)),
            'refreshed_at' => $refreshedAt?->toIso8601String(),
            'bounds' => $this->bounds(),
            'atlas_url' => null,
            'atlas_columns' => 0,
            'atlas_rows' => 0,
            'frame_width' => $frameWidth,
            'frame_height' => $frameHeight,
            'frames' => [],
            'source' => $source,
            'availability_note' => $note,
        ];
    }

    /** @return array{crs: string, west: float, south: float, east: float, north: float} */
    private function bounds(): array
    {
        [$west, $south, $east, $north] = $this->dwdConfiguration->bbox();

        return [
            'crs' => 'EPSG:4326',
            'west' => $west,
            'south' => $south,
            'east' => $east,
            'north' => $north,
        ];
    }

    private function frameToken(
        string $kind,
        CarbonImmutable $validAt,
        ?CarbonImmutable $referenceTime,
        string $phase,
    ): string {
        $validAt = $validAt->utc();
        if ($phase === 'observation') {
            $context = 'o';
        } elseif ($phase === 'forecast' && $referenceTime !== null) {
            $context = 'f'.$referenceTime->utc()->format('Ymd\THis\Z');
        } else {
            throw new \InvalidArgumentException('The radar frame token context is invalid.');
        }
        $hash = substr(hash_hmac(
            'sha256',
            implode('|', [
                'live-radar-v2',
                $kind,
                $validAt->toIso8601String(),
                $context,
            ]),
            $this->frameTokenKey(),
        ), 0, 16);

        return $validAt->format('Ymd\THis\Z').'-'.$context.'-'.$hash;
    }

    private function frameTokenKey(): string
    {
        $configured = config('app.key');
        if (! is_string($configured) || trim($configured) === '') {
            throw new \RuntimeException('The application key is unavailable for radar frame tokens.');
        }
        $raw = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;
        if (! is_string($raw) || strlen($raw) < 32) {
            throw new \RuntimeException('The application key is invalid for radar frame tokens.');
        }

        return hash_hmac('sha256', 'DIS operational radar frame tokens v2', $raw, true);
    }

    /**
     * Decode and validate a self-contained, immutable frame token. Observation
     * tokens intentionally omit a moving radar reference time, so the same
     * source frame keeps the same URL across timeline refreshes.
     *
     * @return array{
     *   valid_at: CarbonImmutable,
     *   reference_time: CarbonImmutable,
     *   phase: 'observation'|'forecast'
     * }|null
     */
    private function frameDescriptor(string $kind, string $token): ?array
    {
        if (! in_array($kind, ['precipitation', 'lightning'], true)
            || preg_match(self::FRAME_PATTERN, $token, $matches) !== 1) {
            return null;
        }
        $validAt = $this->compactTimestamp($matches['valid']);
        if ($validAt === null) {
            return null;
        }

        $context = $matches['context'];
        $phase = $context === 'o' ? 'observation' : 'forecast';
        $referenceTime = $phase === 'observation'
            ? $validAt
            : $this->compactTimestamp(substr($context, 1));
        if ($referenceTime === null
            || ! hash_equals(
                $this->frameToken(
                    $kind,
                    $validAt,
                    $phase === 'forecast' ? $referenceTime : null,
                    $phase,
                ),
                $token,
            )) {
            return null;
        }

        $now = CarbonImmutable::now()->utc();
        if ($validAt->second !== 0
            || $validAt->minute % 5 !== 0
            || $referenceTime->second !== 0
            || $referenceTime->minute % 5 !== 0
            || $referenceTime->greaterThan($now->addMinutes(10))) {
            return null;
        }

        if ($kind === 'lightning') {
            $periodEnd = $validAt->addMinutes($this->lightningConfiguration->intervalMinutes());
            if ($phase !== 'observation'
                || $validAt->greaterThan($now->addMinutes(5))
                || $periodEnd->lessThan(
                    $now->subSeconds($this->lightningConfiguration->frameCacheSeconds()),
                )) {
                return null;
            }
        } elseif ($phase === 'observation') {
            if ($validAt->greaterThan($now->addMinutes(10))
                || $validAt->lessThan(
                    $now->subSeconds($this->dwdConfiguration->frameCacheSeconds()),
                )) {
                return null;
            }
        } else {
            $leadSeconds = $referenceTime->diffInSeconds($validAt, false);
            if ($referenceTime->lessThan(
                $now->subSeconds($this->dwdConfiguration->maximumFallbackAgeSeconds()),
            )
                || $leadSeconds <= 0
                || $leadSeconds > $this->dwdConfiguration->forecastMinutes() * 60
                || $leadSeconds % ($this->dwdConfiguration->intervalMinutes() * 60) !== 0) {
                return null;
            }
        }

        return [
            'valid_at' => $validAt,
            'reference_time' => $referenceTime,
            'phase' => $phase,
        ];
    }

    private function compactTimestamp(string $value): ?CarbonImmutable
    {
        if (preg_match('/\A\d{8}T\d{6}Z\z/D', $value) !== 1) {
            return null;
        }
        try {
            $timestamp = CarbonImmutable::createFromFormat('!Ymd\THis\Z', $value, 'UTC');

            return $timestamp instanceof CarbonImmutable ? $timestamp->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)
            || strlen($value) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
