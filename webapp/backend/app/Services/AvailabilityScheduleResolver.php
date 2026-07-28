<?php

namespace App\Services;

use App\Models\AvailabilityOverride;
use App\Models\AvailabilityWeekPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AvailabilityScheduleResolver
{
    private const DAY_PART_ALL_DAY = 'all_day';

    private const DAY_PART_MORNING = 'morning';

    private const DAY_PART_AFTERNOON = 'afternoon';

    private const DAY_PART_EVENING = 'evening';

    /**
     * @return array{is_available: bool, source: string, note: string|null}
     */
    public function availabilityFor(User $user, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::now();
        $dayPart = $this->dayPartFor($date);

        $overrides = AvailabilityOverride::query()
            ->where('user_id', $user->id)
            ->whereDate('starts_at', '<=', $date->toDateString())
            ->whereDate('ends_at', '>=', $date->toDateString())
            ->whereIn('day_part', [self::DAY_PART_ALL_DAY, $dayPart])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
        $override = $this->authoritativeOverride($overrides, $dayPart);
        if ($override !== null) {
            return [
                'is_available' => (bool) $override->is_available,
                'source' => 'override',
                'note' => $override->note,
            ];
        }

        $pattern = AvailabilityWeekPattern::query()
            ->where('user_id', $user->id)
            ->where('day_of_week', $date->dayOfWeekIso)
            ->whereIn('day_part', [self::DAY_PART_ALL_DAY, $dayPart])
            ->orderByRaw('case when day_part = ? then 0 else 1 end', [$dayPart])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
        if ($pattern !== null) {
            return [
                'is_available' => (bool) $pattern->is_available,
                'source' => 'week_pattern',
                'note' => $pattern->note,
            ];
        }

        return [
            'is_available' => true,
            'source' => 'default',
            'note' => null,
        ];
    }

    /**
     * Resolve the current schedule for a read-model cohort without issuing two
     * schedule queries per user.
     *
     * @param  Collection<int, User>  $users
     * @return array<string, bool>
     */
    public function availabilityByUser(Collection $users, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::now();
        $userIds = $users->pluck('id')->map(fn (mixed $id): string => (string) $id)->unique()->values();
        if ($userIds->isEmpty()) {
            return [];
        }

        $dayPart = $this->dayPartFor($date);
        $overrides = AvailabilityOverride::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('starts_at', '<=', $date->toDateString())
            ->whereDate('ends_at', '>=', $date->toDateString())
            ->whereIn('day_part', [self::DAY_PART_ALL_DAY, $dayPart])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'day_part', 'is_available', 'updated_at'])
            ->groupBy(fn (AvailabilityOverride $override): string => (string) $override->user_id);
        $patterns = AvailabilityWeekPattern::query()
            ->whereIn('user_id', $userIds)
            ->where('day_of_week', $date->dayOfWeekIso)
            ->whereIn('day_part', [self::DAY_PART_ALL_DAY, $dayPart])
            ->orderByRaw('case when day_part = ? then 0 else 1 end', [$dayPart])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'day_part', 'is_available', 'updated_at'])
            ->unique(fn (AvailabilityWeekPattern $pattern): string => (string) $pattern->user_id)
            ->keyBy(fn (AvailabilityWeekPattern $pattern): string => (string) $pattern->user_id);

        return $users->mapWithKeys(function (User $user) use ($overrides, $patterns, $dayPart): array {
            $userId = (string) $user->id;
            $userOverrides = $overrides->get($userId, collect());
            $override = $this->authoritativeOverride($userOverrides, $dayPart);
            $pattern = $patterns->get($userId);

            return [$userId => $override instanceof AvailabilityOverride
                ? (bool) $override->is_available
                : ($pattern instanceof AvailabilityWeekPattern ? (bool) $pattern->is_available : true)];
        })->all();
    }

    /**
     * A current all-day override is authoritative for every day part. Within
     * each scope, the most recently updated record remains authoritative so an
     * explicitly replaced all-day override can still change that boundary.
     *
     * @param  Collection<int, AvailabilityOverride>  $overrides
     */
    private function authoritativeOverride(Collection $overrides, string $dayPart): ?AvailabilityOverride
    {
        $allDay = $overrides->first(
            fn (AvailabilityOverride $override): bool => $override->day_part === self::DAY_PART_ALL_DAY,
        );
        $specific = $overrides->first(
            fn (AvailabilityOverride $override): bool => $override->day_part === $dayPart,
        );

        return $allDay instanceof AvailabilityOverride ? $allDay : $specific;
    }

    private function dayPartFor(CarbonImmutable $date): string
    {
        $hour = $date->hour;

        return match (true) {
            $hour < 12 => self::DAY_PART_MORNING,
            $hour < 18 => self::DAY_PART_AFTERNOON,
            default => self::DAY_PART_EVENING,
        };
    }
}
