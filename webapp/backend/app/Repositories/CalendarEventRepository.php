<?php

namespace App\Repositories;

use App\Models\CalendarEvent;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<CalendarEvent>
 */
final class CalendarEventRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return CalendarEvent::class;
    }

    /** @return Collection<int, CalendarEvent> */
    public function currentAndUpcoming(DateTimeInterface $now, int $limit): Collection
    {
        return $this->query()
            // A wallboard has no authenticated team context. Only organisation-wide
            // calendar items may therefore be exposed on this public display surface.
            ->whereNull('team_id')
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->where(function (Builder $pointEvents) use ($now): void {
                        $pointEvents
                            ->whereNull('ends_at')
                            ->where('starts_at', '>=', $now);
                    })
                    ->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 12)))
            ->get([
                'id',
                'title',
                'type',
                'starts_at',
                'ends_at',
                'location_label',
                'description',
                'team_id',
            ]);
    }
}
