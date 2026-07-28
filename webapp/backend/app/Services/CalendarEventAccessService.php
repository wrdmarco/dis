<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\CalendarEventRegistration;
use App\Models\CalendarGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class CalendarEventAccessService
{
    public function canView(User $actor, CalendarEvent $event): bool
    {
        if (! $actor->hasPermission('calendar.view')) {
            return false;
        }

        if ($this->canViewEveryEvent($actor)) {
            return true;
        }

        if ($this->isRegistered($event, $actor)) {
            return true;
        }

        return $this->isAudienceMember($event, $actor);
    }

    public function assertCanView(User $actor, CalendarEvent $event): void
    {
        if (! $this->canView($actor, $event)) {
            throw new AuthorizationException;
        }
    }

    public function canRegister(User $actor, CalendarEvent $event): bool
    {
        return $actor->hasPermission('calendar.view')
            && $actor->hasPermission('calendar.register')
            && $this->isAudienceMember($event, $actor);
    }

    public function assertCanRegister(User $actor, CalendarEvent $event): void
    {
        if (! $this->canRegister($actor, $event)) {
            throw new AuthorizationException;
        }
    }

    public function canUnregister(User $actor, CalendarEvent $event): bool
    {
        return $actor->hasPermission('calendar.view')
            && $actor->hasPermission('calendar.register')
            && $this->isRegistered($event, $actor);
    }

    public function canViewParticipants(User $actor): bool
    {
        return $actor->hasPermission('calendar.view')
            && $actor->hasPermission('calendar.registrations.view');
    }

    public function canManageParticipants(User $actor): bool
    {
        return $actor->hasPermission('calendar.view')
            && $actor->hasPermission('calendar.registrations.manage');
    }

    public function canViewEveryEvent(User $actor): bool
    {
        return $actor->hasPermission('calendar.manage')
            || $this->canViewParticipants($actor)
            || $this->canManageParticipants($actor);
    }

    public function isAudienceMember(CalendarEvent $event, User $user): bool
    {
        if (array_key_exists('current_user_audience_member', $event->getAttributes())) {
            return (bool) $event->getAttribute('current_user_audience_member');
        }

        return $event->audienceGroups()
            ->where(function ($groups) use ($user): void {
                $groups
                    ->where('is_everyone', true)
                    ->orWhereHas(
                        'directUsers',
                        fn ($users) => $users->whereKey($user->id),
                    )
                    ->orWhereHas(
                        'teams.users',
                        fn ($users) => $users->whereKey($user->id),
                    );
            })
            ->exists();
    }

    public function isRegistered(CalendarEvent $event, User $user): bool
    {
        if (array_key_exists('current_user_registered', $event->getAttributes())) {
            return (bool) $event->getAttribute('current_user_registered');
        }

        return CalendarEventRegistration::query()
            ->where('calendar_event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('status', 'registered')
            ->exists();
    }

    /** @return list<string> */
    public function effectiveCalendarGroupIds(User $user): array
    {
        return CalendarGroup::query()
            ->where(function ($groups) use ($user): void {
                $groups
                    ->where('is_everyone', true)
                    ->orWhereHas(
                        'directUsers',
                        fn ($users) => $users->whereKey($user->id),
                    )
                    ->orWhereHas(
                        'teams.users',
                        fn ($users) => $users->whereKey($user->id),
                    );
            })
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }
}
