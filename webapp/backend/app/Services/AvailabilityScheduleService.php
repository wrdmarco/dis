<?php

namespace App\Services;

use App\Models\AvailabilityOverride;
use App\Models\AvailabilityWeekPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AvailabilityScheduleService
{
    private const DAY_PART_ALL_DAY = 'all_day';
    private const DAY_PART_MORNING = 'morning';
    private const DAY_PART_AFTERNOON = 'afternoon';
    private const DAY_PART_EVENING = 'evening';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly StatusService $statusService,
    ) {}

    public function isAvailable(User $user, ?CarbonImmutable $date = null): bool
    {
        return $this->availabilityFor($user, $date)['is_available'];
    }

    /**
     * @return array{checked: int, updated: int}
     */
    public function syncCurrentStatuses(?User $actor = null): array
    {
        $checked = 0;
        $updated = 0;

        User::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $users) use ($actor, &$checked, &$updated): void {
                foreach ($users as $user) {
                    $checked++;
                    if ($this->syncCurrentStatusForToday($user, $actor)) {
                        $updated++;
                    }
                }
            });

        return ['checked' => $checked, 'updated' => $updated];
    }

    /**
     * @return array{is_available: bool, source: string, note: string|null}
     */
    public function availabilityFor(User $user, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::now();
        $dayPart = $this->dayPartFor($date);

        $override = AvailabilityOverride::query()
            ->where('user_id', $user->id)
            ->whereDate('starts_at', '<=', $date->toDateString())
            ->whereDate('ends_at', '>=', $date->toDateString())
            ->whereIn('day_part', [self::DAY_PART_ALL_DAY, $dayPart])
            ->orderByRaw("case when day_part = ? then 0 else 1 end", [$dayPart])
            ->latest('updated_at')
            ->first();
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
            ->orderByRaw("case when day_part = ? then 0 else 1 end", [$dayPart])
            ->latest('updated_at')
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
     * @return array{at: string, is_available: bool, source: string, note: string|null}|null
     */
    public function nextAvailabilityChange(User $user, ?CarbonImmutable $from = null, int $daysAhead = 14): ?array
    {
        if (! $user->push_enabled) {
            return null;
        }

        $from ??= CarbonImmutable::now();
        $current = $this->availabilityFor($user, $from);

        foreach ($this->availabilityCheckpoints($from, $daysAhead) as $checkpoint) {
            $availability = $this->availabilityFor($user, $checkpoint);
            if ($availability['is_available'] !== $current['is_available']) {
                return [
                    'at' => $checkpoint->toIso8601String(),
                    'is_available' => $availability['is_available'],
                    'source' => $availability['source'],
                    'note' => $availability['note'],
                ];
            }
        }

        return null;
    }

    /**
     * @param array<int, array{day_of_week: int, day_part?: string|null, is_available: bool, note?: string|null}> $patterns
     * @return Collection<int, AvailabilityWeekPattern>
     */
    public function replaceWeekPattern(User $user, array $patterns, User $actor): Collection
    {
        return DB::transaction(function () use ($user, $patterns, $actor): Collection {
            AvailabilityWeekPattern::query()->where('user_id', $user->id)->delete();
            $records = collect($patterns)
                ->map(fn (array $pattern): AvailabilityWeekPattern => AvailabilityWeekPattern::query()->create([
                    'user_id' => $user->id,
                    'day_of_week' => $pattern['day_of_week'],
                    'day_part' => $pattern['day_part'] ?? self::DAY_PART_ALL_DAY,
                    'is_available' => $pattern['is_available'],
                    'note' => $pattern['note'] ?? null,
                    'created_by' => $actor->id,
                ]))
                ->values();

            $this->auditService->record('availability.week_pattern_updated', $user, $actor, [
                'days' => $records->map(fn (AvailabilityWeekPattern $record): array => [
                    'day_of_week' => $record->day_of_week,
                    'day_part' => $record->day_part,
                    'is_available' => $record->is_available,
                ])->values()->all(),
            ]);

            $this->syncCurrentStatusForToday($user, $actor);

            return $records;
        });
    }

    /**
     * @param array{starts_at: string, ends_at: string, is_available: bool, note?: string|null} $data
     */
    public function createOverride(User $user, array $data, User $actor): AvailabilityOverride
    {
        $override = AvailabilityOverride::query()->create([
            'user_id' => $user->id,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'day_part' => $data['day_part'] ?? self::DAY_PART_ALL_DAY,
            'is_available' => $data['is_available'],
            'note' => $data['note'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->auditService->record('availability.override_created', $user, $actor, [
            'starts_at' => $override->starts_at?->toDateString(),
            'ends_at' => $override->ends_at?->toDateString(),
            'day_part' => $override->day_part,
            'is_available' => $override->is_available,
        ]);
        $this->syncCurrentStatusForToday($user, $actor);

        return $override;
    }

    public function deleteOverride(AvailabilityOverride $override, User $actor): void
    {
        $user = $override->user;
        $metadata = [
            'starts_at' => $override->starts_at?->toDateString(),
            'ends_at' => $override->ends_at?->toDateString(),
            'day_part' => $override->day_part,
            'is_available' => $override->is_available,
        ];
        $override->delete();
        if ($user !== null) {
            $this->auditService->record('availability.override_deleted', $user, $actor, $metadata);
            $this->syncCurrentStatusForToday($user, $actor);
        }
    }

    private function syncCurrentStatusForToday(User $user, ?User $actor): bool
    {
        $availability = $this->availabilityFor($user);
        $latestStatus = $user->statuses()
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latestStatus !== null && ! in_array($latestStatus->status, ['available', 'unavailable'], true)) {
            return false;
        }

        $pushDisabled = ! $user->push_enabled;
        $targetStatus = $availability['is_available'] && ! $pushDisabled ? 'available' : 'unavailable';
        if ($latestStatus?->status === $targetStatus) {
            return false;
        }

        $reason = $pushDisabled
            ? 'Pushmeldingen staan uit; automatisch niet beschikbaar.'
            : (
                $availability['source'] === 'override'
                    ? 'Automatisch bijgewerkt vanuit beschikbaarheidsplanning.'
                    : 'Automatisch bijgewerkt vanuit vast beschikbaarheidspatroon.'
            );

        $this->statusService->setStatus($user, $targetStatus, $actor, $reason, true);

        return true;
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function availabilityCheckpoints(CarbonImmutable $from, int $daysAhead): array
    {
        $start = $from->startOfDay();
        $checkpoints = [];

        for ($dayOffset = 0; $dayOffset <= $daysAhead; $dayOffset++) {
            $day = $start->addDays($dayOffset);
            foreach ([0, 12, 18] as $hour) {
                $checkpoint = $day->setTime($hour, 0);
                if ($checkpoint->greaterThan($from)) {
                    $checkpoints[] = $checkpoint;
                }
            }
        }

        usort($checkpoints, fn (CarbonImmutable $left, CarbonImmutable $right): int => $left <=> $right);

        return $checkpoints;
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
