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
    public function __construct(private readonly AuditService $auditService) {}

    public function isAvailable(User $user, ?CarbonImmutable $date = null): bool
    {
        return $this->availabilityFor($user, $date)['is_available'];
    }

    /**
     * @return array{is_available: bool, source: string, note: string|null}
     */
    public function availabilityFor(User $user, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::today();

        $override = AvailabilityOverride::query()
            ->where('user_id', $user->id)
            ->whereDate('starts_at', '<=', $date->toDateString())
            ->whereDate('ends_at', '>=', $date->toDateString())
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
     * @param array<int, array{day_of_week: int, is_available: bool, note?: string|null}> $patterns
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
                    'is_available' => $pattern['is_available'],
                    'note' => $pattern['note'] ?? null,
                    'created_by' => $actor->id,
                ]))
                ->values();

            $this->auditService->record('availability.week_pattern_updated', $user, $actor, [
                'days' => $records->map(fn (AvailabilityWeekPattern $record): array => [
                    'day_of_week' => $record->day_of_week,
                    'is_available' => $record->is_available,
                ])->values()->all(),
            ]);

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
            'is_available' => $data['is_available'],
            'note' => $data['note'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->auditService->record('availability.override_created', $user, $actor, [
            'starts_at' => $override->starts_at?->toDateString(),
            'ends_at' => $override->ends_at?->toDateString(),
            'is_available' => $override->is_available,
        ]);

        return $override;
    }

    public function deleteOverride(AvailabilityOverride $override, User $actor): void
    {
        $user = $override->user;
        $metadata = [
            'starts_at' => $override->starts_at?->toDateString(),
            'ends_at' => $override->ends_at?->toDateString(),
            'is_available' => $override->is_available,
        ];
        $override->delete();
        if ($user !== null) {
            $this->auditService->record('availability.override_deleted', $user, $actor, $metadata);
        }
    }
}
