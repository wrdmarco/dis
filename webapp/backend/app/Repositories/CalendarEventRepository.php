<?php

namespace App\Repositories;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\CalendarEventAccessService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<CalendarEvent>
 */
final class CalendarEventRepository extends BaseRepository
{
    public function __construct(
        private readonly CalendarEventAccessService $access,
    ) {}

    protected function modelClass(): string
    {
        return CalendarEvent::class;
    }

    public function lock(string $id): CalendarEvent
    {
        return $this->query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function forPresentation(string $id, ?User $actor = null): CalendarEvent
    {
        $event = $this->presentationQuery($actor)->findOrFail($id);

        return $this->markAudienceMembership($event, $actor);
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function visibleBetween(
        User $actor,
        DateTimeInterface $from,
        DateTimeInterface $until,
        int $limit,
    ): Collection {
        $groupIds = $this->access->effectiveCalendarGroupIds($actor);
        $query = $this->presentationQuery($actor)
            ->where(function (Builder $query) use ($from): void {
                $query
                    ->where(function (Builder $pointEvents) use ($from): void {
                        $pointEvents
                            ->whereNull('ends_at')
                            ->where('starts_at', '>=', $from);
                    })
                    ->orWhere('ends_at', '>=', $from);
            })
            ->where('starts_at', '<=', $until);

        if (! $this->access->canViewEveryEvent($actor)) {
            $query->where(function (Builder $visible) use ($actor, $groupIds): void {
                $visible
                    ->whereHas(
                        'registrations',
                        fn (Builder $registrations) => $registrations
                            ->where('user_id', $actor->id)
                            ->where('status', 'registered'),
                    );

                if ($groupIds !== []) {
                    $visible->orWhereHas(
                        'audienceGroups',
                        fn (Builder $groups) => $groups->whereIn('calendar_groups.id', $groupIds),
                    );
                }
            });
        }

        return $query
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->each(fn (CalendarEvent $event): CalendarEvent => $this->markAudienceMembership(
                $event,
                $actor,
                $groupIds,
            ));
    }

    public function participantCount(CalendarEvent $event): int
    {
        return $event->registrations()
            ->where('status', 'registered')
            ->count();
    }

    /** @return Collection<int, CalendarEvent> */
    public function currentAndUpcoming(DateTimeInterface $now, int $limit): Collection
    {
        return $this->query()
            // The protected Iedereen group is the sole wallboard audience authority.
            // Legacy team_id values must never decide visibility.
            ->whereHas(
                'audienceGroups',
                fn (Builder $groups) => $groups->where('is_everyone', true),
            )
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
                'audience_scope',
                'registration_enabled',
                'max_participants',
                'team_id',
            ]);
    }

    /** @return Builder<CalendarEvent> */
    private function presentationQuery(?User $actor): Builder
    {
        return $this->query()
            ->with([
                'team:id,code,name,type',
                'creator:id,name',
                'audienceGroups:id,name,is_everyone',
            ])
            ->withCount([
                'registrations as participant_count' => fn (Builder $registrations) => $registrations
                    ->where('status', 'registered'),
            ])
            ->when(
                $actor !== null,
                fn (Builder $query) => $query->withExists([
                    'registrations as current_user_registered' => fn (Builder $registrations) => $registrations
                        ->where('user_id', $actor->id)
                        ->where('status', 'registered'),
                ]),
            );
    }

    /**
     * @param  list<string>|null  $groupIds
     */
    private function markAudienceMembership(
        CalendarEvent $event,
        ?User $actor,
        ?array $groupIds = null,
    ): CalendarEvent {
        if ($actor === null) {
            return $event;
        }

        $groupIds ??= $this->access->effectiveCalendarGroupIds($actor);
        $eventGroupIds = $event->audienceGroups
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $isMember = array_intersect($eventGroupIds, $groupIds) !== [];

        $event->setAttribute('current_user_audience_member', $isMember);

        return $event;
    }
}
