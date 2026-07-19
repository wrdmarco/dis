<?php

namespace App\Repositories;

use App\Models\SystemSetting;

final class SystemUpdateDurationHistoryRepository
{
    private const SETTING_KEY = 'system.update_duration_history';

    private const MAX_SAMPLES_PER_MODE = 20;

    /** @return list<int> */
    public function durations(bool $includesSystemUpdates): array
    {
        $history = SystemSetting::value(self::SETTING_KEY, []);
        if (! is_array($history)) {
            return [];
        }

        $mode = $includesSystemUpdates ? 'system' : 'application';
        $durations = $history[$mode] ?? [];
        if (! is_array($durations)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $duration): ?int => is_int($duration) ? $duration : null,
                $durations,
            ),
            static fn (?int $duration): bool => $duration !== null,
        ));
    }

    public function append(bool $includesSystemUpdates, int $durationSeconds): void
    {
        $history = SystemSetting::value(self::SETTING_KEY, []);
        if (! is_array($history)) {
            $history = [];
        }

        $mode = $includesSystemUpdates ? 'system' : 'application';
        $durations = $this->durations($includesSystemUpdates);
        $durations[] = $durationSeconds;
        $history[$mode] = array_slice($durations, -self::MAX_SAMPLES_PER_MODE);

        SystemSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            [
                'value' => $history,
                'is_sensitive' => false,
                'updated_by' => null,
            ],
        );
    }
}
