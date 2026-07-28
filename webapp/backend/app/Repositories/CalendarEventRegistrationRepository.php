<?php

namespace App\Repositories;

use App\Models\CalendarEvent;
use App\Models\CalendarEventRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<CalendarEventRegistration>
 */
final class CalendarEventRegistrationRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return CalendarEventRegistration::class;
    }

    public function forEventUserForUpdate(
        CalendarEvent $event,
        User $user,
    ): ?CalendarEventRegistration {
        return $this->query()
            ->where('calendar_event_id', $event->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
    }

    public function activeCount(CalendarEvent $event): int
    {
        return $this->query()
            ->where('calendar_event_id', $event->id)
            ->where('status', CalendarEventRegistration::STATUS_REGISTERED)
            ->count();
    }

    /** @return Collection<int, CalendarEventRegistration> */
    public function roster(CalendarEvent $event): Collection
    {
        return $this->query()
            ->with('user:id,name,email')
            ->where('calendar_event_id', $event->id)
            ->where('status', CalendarEventRegistration::STATUS_REGISTERED)
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function registrationOptions(
        CalendarEvent $event,
        ?string $search,
    ): Collection {
        $normalized = is_string($search) && trim($search) !== ''
            ? trim($search)
            : null;
        $groupIds = $event->audienceGroups()
            ->pluck('calendar_groups.id')
            ->all();
        $includesEveryone = $event->audienceGroups()
            ->where('is_everyone', true)
            ->exists();

        return User::query()
            ->withoutStoreReview()
            ->where('account_status', 'active')
            ->when(
                ! $includesEveryone,
                function (Builder $users) use ($groupIds): void {
                    $users->where(function (Builder $audience) use ($groupIds): void {
                        if ($groupIds === []) {
                            $audience->whereRaw('1 = 0');

                            return;
                        }

                        $audience
                            ->whereHas(
                                'calendarGroups',
                                fn (Builder $groups) => $groups
                                    ->whereIn('calendar_groups.id', $groupIds),
                            )
                            ->orWhereHas(
                                'teams.calendarGroups',
                                fn (Builder $groups) => $groups
                                    ->whereIn('calendar_groups.id', $groupIds),
                            );
                    });
                },
            )
            ->whereNotExists(function ($registrations) use ($event): void {
                $registrations
                    ->selectRaw('1')
                    ->from('calendar_event_registrations')
                    ->whereColumn('calendar_event_registrations.user_id', 'users.id')
                    ->where('calendar_event_registrations.calendar_event_id', $event->id)
                    ->where(
                        'calendar_event_registrations.status',
                        CalendarEventRegistration::STATUS_REGISTERED,
                    );
            })
            ->when($normalized !== null, function (Builder $query) use ($normalized): void {
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($normalized));
                $pattern = '%'.$escaped.'%';
                $query->where(function (Builder $searchQuery) use ($pattern): void {
                    $searchQuery
                        ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'name', 'email']);
    }
}
