<?php

namespace App\Services;

use App\Repositories\SystemUpdateDurationHistoryRepository;
use Throwable;

final class SystemUpdateDurationEstimator
{
    private const MIN_SAMPLE_SECONDS = 30;

    private const MAX_SAMPLE_SECONDS = 3600;

    private const MIN_ESTIMATE_SECONDS = 180;

    private const MAX_ESTIMATE_SECONDS = 2700;

    private const SAFETY_MARGIN_PERCENT = 15;

    public function __construct(
        private readonly SystemUpdateDurationHistoryRepository $history,
    ) {}

    /** @return array{duration_seconds: int, source: 'historical'|'fallback', sample_count: int} */
    public function estimate(bool $includesSystemUpdates): array
    {
        try {
            $durations = $this->history->durations($includesSystemUpdates);
        } catch (Throwable $exception) {
            report($exception);
            $durations = [];
        }

        $samples = array_values(array_filter(
            $durations,
            static fn (int $duration): bool => $duration >= self::MIN_SAMPLE_SECONDS
                && $duration <= self::MAX_SAMPLE_SECONDS,
        ));

        if ($samples === []) {
            return [
                'duration_seconds' => $this->fallback($includesSystemUpdates),
                'source' => 'fallback',
                'sample_count' => 0,
            ];
        }

        sort($samples, SORT_NUMERIC);
        $count = count($samples);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $samples[$middle]
            : (int) round(($samples[$middle - 1] + $samples[$middle]) / 2);
        $estimate = (int) ceil($median * (100 + self::SAFETY_MARGIN_PERCENT) / 100);

        return [
            'duration_seconds' => min(self::MAX_ESTIMATE_SECONDS, max(self::MIN_ESTIMATE_SECONDS, $estimate)),
            'source' => 'historical',
            'sample_count' => $count,
        ];
    }

    public function recordSuccessfulRun(bool $includesSystemUpdates, int $durationSeconds): void
    {
        if ($durationSeconds < self::MIN_SAMPLE_SECONDS || $durationSeconds > self::MAX_SAMPLE_SECONDS) {
            return;
        }

        try {
            $this->history->append($includesSystemUpdates, $durationSeconds);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function fallback(bool $includesSystemUpdates): int
    {
        $configured = (int) config(
            $includesSystemUpdates
                ? 'dis.updates.system_estimated_duration_seconds'
                : 'dis.updates.application_estimated_duration_seconds',
            $includesSystemUpdates ? 1500 : 900,
        );

        return min(self::MAX_ESTIMATE_SECONDS, max(self::MIN_ESTIMATE_SECONDS, $configured));
    }
}
