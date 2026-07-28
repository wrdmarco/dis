<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Repositories\CalendarEventRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CalendarEventService
{
    /** @var list<string> */
    private const MUTABLE_FIELDS = [
        'title',
        'type',
        'starts_at',
        'ends_at',
        'location_label',
        'description',
        'team_id',
    ];

    public function __construct(
        private readonly CalendarEventRepository $events,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, Request $request): CalendarEvent
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $actor, $request): CalendarEvent {
            $created = $this->events->create($data + [
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            if (! $created instanceof CalendarEvent) {
                throw new \LogicException('Calendar event repository returned an unexpected model.');
            }

            $this->audit->record(
                'calendar_events.created',
                $created,
                $actor,
                [],
                null,
                $request,
            );

            return $this->events->forPresentation((string) $created->id);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(
        CalendarEvent $calendarEvent,
        array $data,
        User $actor,
        Request $request,
    ): CalendarEvent {
        $this->authorize($actor);

        return DB::transaction(function () use ($calendarEvent, $data, $actor, $request): CalendarEvent {
            $locked = $this->events->lock((string) $calendarEvent->id);
            $locked->fill($data);
            $changedFields = array_values(array_intersect(
                self::MUTABLE_FIELDS,
                array_keys($locked->getDirty()),
            ));

            if ($changedFields === []) {
                return $this->events->forPresentation((string) $locked->id);
            }

            $locked->updated_by = $actor->id;
            $locked->save();
            $this->audit->record(
                'calendar_events.updated',
                $locked,
                $actor,
                [
                    'changed_fields' => $changedFields,
                    'team_id' => $locked->team_id,
                ],
                null,
                $request,
            );

            return $this->events->forPresentation((string) $locked->id);
        }, 3);
    }

    public function delete(
        CalendarEvent $calendarEvent,
        User $actor,
        Request $request,
    ): void {
        $this->authorize($actor);

        DB::transaction(function () use ($calendarEvent, $actor, $request): void {
            $locked = $this->events->lock((string) $calendarEvent->id);
            $this->audit->record(
                'calendar_events.deleted',
                $locked,
                $actor,
                [],
                null,
                $request,
            );
            $locked->delete();
        }, 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasPermission('calendar.view') || ! $actor->hasPermission('calendar.manage')) {
            throw new AuthorizationException;
        }
    }
}
