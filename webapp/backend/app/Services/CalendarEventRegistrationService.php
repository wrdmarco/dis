<?php

namespace App\Services;

use App\Exceptions\CalendarEventConflictException;
use App\Models\CalendarEvent;
use App\Models\CalendarEventRegistration;
use App\Models\User;
use App\Repositories\CalendarEventRegistrationRepository;
use App\Repositories\CalendarEventRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CalendarEventRegistrationService
{
    public function __construct(
        private readonly CalendarEventRepository $events,
        private readonly CalendarEventRegistrationRepository $registrations,
        private readonly CalendarEventAccessService $access,
        private readonly AuditService $audit,
    ) {}

    public function registerSelf(
        CalendarEvent $event,
        User $actor,
        Request $request,
    ): CalendarEvent {
        $this->assertSelfPermission($actor);

        return $this->register($event, $actor, $actor, $request, 'self');
    }

    public function unregisterSelf(
        CalendarEvent $event,
        User $actor,
        Request $request,
    ): CalendarEvent {
        $this->assertSelfPermission($actor);

        return $this->unregister($event, $actor, $actor, $request, 'self');
    }

    public function registerForUser(
        CalendarEvent $event,
        User $target,
        User $actor,
        Request $request,
    ): CalendarEvent {
        $this->assertManagementPermission($actor);

        return $this->register($event, $target, $actor, $request, 'admin');
    }

    public function unregisterForUser(
        CalendarEvent $event,
        User $target,
        User $actor,
        Request $request,
    ): CalendarEvent {
        $this->assertManagementPermission($actor);

        return $this->unregister($event, $target, $actor, $request, 'admin');
    }

    /** @return Collection<int, CalendarEventRegistration> */
    public function roster(CalendarEvent $event, User $actor): Collection
    {
        if (! $this->access->canViewParticipants($actor)) {
            throw new AuthorizationException;
        }
        $this->access->assertCanView($actor, $event);

        return $this->registrations->roster($event);
    }

    /** @return Collection<int, User> */
    public function options(
        CalendarEvent $event,
        User $actor,
        ?string $search,
    ): Collection {
        $this->assertManagementPermission($actor);
        $this->access->assertCanView($actor, $event);

        return $this->registrations->registrationOptions($event, $search);
    }

    private function register(
        CalendarEvent $event,
        User $target,
        User $actor,
        Request $request,
        string $mode,
    ): CalendarEvent {
        return DB::transaction(function () use ($event, $target, $actor, $request, $mode): CalendarEvent {
            $locked = $this->events->lock((string) $event->id);
            $this->access->assertCanView($actor, $locked);
            $lockedTarget = User::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->first();
            $registration = $this->registrations->forEventUserForUpdate(
                $locked,
                $lockedTarget ?? $target,
            );

            if ($registration?->status === CalendarEventRegistration::STATUS_REGISTERED) {
                return $this->events->forPresentation((string) $locked->id, $actor);
            }
            if ($lockedTarget === null || $lockedTarget->account_status !== 'active') {
                throw new AuthorizationException;
            }

            if (! $this->access->isAudienceMember($locked, $lockedTarget)) {
                throw new AuthorizationException;
            }
            if (! $locked->registration_enabled) {
                throw new CalendarEventConflictException(
                    'calendar_registration_closed',
                    'Inschrijven voor dit agenda-item is gesloten.',
                    ['registration' => $this->registrationState($locked)],
                );
            }

            $participantCount = $this->registrations->activeCount($locked);
            if (
                $locked->max_participants !== null
                && $participantCount >= (int) $locked->max_participants
            ) {
                throw new CalendarEventConflictException(
                    'calendar_event_full',
                    'Dit agenda-item is vol; inschrijven is gesloten.',
                    ['registration' => $this->registrationState($locked, $participantCount)],
                );
            }

            $registration ??= new CalendarEventRegistration([
                'calendar_event_id' => $locked->id,
                'user_id' => $lockedTarget->id,
            ]);
            $registration->fill([
                'user_id' => $lockedTarget->id,
                'user_name' => $lockedTarget->name,
                'status' => CalendarEventRegistration::STATUS_REGISTERED,
                'registered_by' => $actor->id,
                'registered_by_name' => $actor->name,
                'cancelled_by' => null,
                'cancelled_by_name' => null,
                'registered_at' => now(),
                'cancelled_at' => null,
            ]);
            $registration->save();
            $participantCount++;

            $this->audit->record(
                'calendar_registrations.registered',
                $locked,
                $actor,
                [
                    'registration_id' => (string) $registration->id,
                    'user_id' => (string) $lockedTarget->id,
                    'user_name' => $lockedTarget->name,
                    'mode' => $mode,
                    'participant_count_after' => $participantCount,
                    'max_participants' => $locked->max_participants,
                ],
                null,
                $request,
            );

            return $this->events->forPresentation((string) $locked->id, $actor);
        }, 3);
    }

    private function unregister(
        CalendarEvent $event,
        User $target,
        User $actor,
        Request $request,
        string $mode,
    ): CalendarEvent {
        return DB::transaction(function () use ($event, $target, $actor, $request, $mode): CalendarEvent {
            $locked = $this->events->lock((string) $event->id);
            $this->access->assertCanView($actor, $locked);
            User::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->first();
            $registration = $this->registrations->forEventUserForUpdate($locked, $target);
            if ($registration?->status !== CalendarEventRegistration::STATUS_REGISTERED) {
                return $this->events->forPresentation((string) $locked->id, $actor);
            }

            $registration->fill([
                'status' => CalendarEventRegistration::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_by_name' => $actor->name,
                'cancelled_at' => now(),
            ]);
            $registration->save();
            $participantCount = $this->registrations->activeCount($locked);

            $this->audit->record(
                'calendar_registrations.cancelled',
                $locked,
                $actor,
                [
                    'registration_id' => (string) $registration->id,
                    'user_id' => (string) $target->id,
                    'user_name' => $registration->user_name,
                    'mode' => $mode,
                    'participant_count_after' => $participantCount,
                    'max_participants' => $locked->max_participants,
                ],
                null,
                $request,
            );

            return $this->events->forPresentation((string) $locked->id, $actor);
        }, 3);
    }

    private function assertSelfPermission(User $actor): void
    {
        if (
            ! $actor->hasPermission('calendar.view')
            || ! $actor->hasPermission('calendar.register')
        ) {
            throw new AuthorizationException;
        }
    }

    private function assertManagementPermission(User $actor): void
    {
        if (! $this->access->canManageParticipants($actor)) {
            throw new AuthorizationException;
        }
    }

    /** @return array<string, mixed> */
    private function registrationState(
        CalendarEvent $event,
        ?int $participantCount = null,
    ): array {
        $participantCount ??= $this->registrations->activeCount($event);
        $full = $event->max_participants !== null
            && $participantCount >= (int) $event->max_participants;

        return [
            'enabled' => (bool) $event->registration_enabled,
            'status' => ! $event->registration_enabled
                ? 'closed'
                : ($full ? 'full' : 'open'),
            'max_participants' => $event->max_participants === null
                ? null
                : (int) $event->max_participants,
            'participant_count' => $participantCount,
        ];
    }
}
