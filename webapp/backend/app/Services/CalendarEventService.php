<?php

namespace App\Services;

use App\Exceptions\CalendarEventConflictException;
use App\Models\CalendarEvent;
use App\Models\CalendarGroup;
use App\Models\User;
use App\Repositories\CalendarEventRepository;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        'audience_scope',
        'registration_enabled',
        'max_participants',
    ];

    public function __construct(
        private readonly CalendarEventRepository $events,
        private readonly AuditService $audit,
    ) {}

    /** @return Collection<int, CalendarEvent> */
    public function visibleBetween(
        User $actor,
        DateTimeInterface $from,
        DateTimeInterface $until,
        int $limit,
    ): Collection {
        if (! $actor->hasPermission('calendar.view')) {
            throw new AuthorizationException;
        }

        return $this->events->visibleBetween($actor, $from, $until, $limit);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, Request $request): CalendarEvent
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $actor, $request): CalendarEvent {
            $groupIds = $this->groupIds($data);
            $groups = $this->lockGroups($groupIds);
            $attributes = $this->eventAttributes($data);
            $attributes['audience_scope'] = $this->audienceScope($groups);
            // The legacy team column remains response-only compatibility data.
            $attributes['team_id'] = null;

            $created = $this->events->create($attributes + [
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            if (! $created instanceof CalendarEvent) {
                throw new \LogicException('Calendar event repository returned an unexpected model.');
            }

            $created->audienceGroups()->sync($groupIds);
            $this->audit->record(
                'calendar_events.created',
                $created,
                $actor,
                [
                    'group_ids' => $groupIds,
                    'registration_enabled' => (bool) $created->registration_enabled,
                    'max_participants' => $created->max_participants,
                ],
                null,
                $request,
            );

            return $this->events->forPresentation((string) $created->id, $actor);
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
            $beforeGroupIds = $locked->audienceGroups()
                ->pluck('calendar_groups.id')
                ->map(static fn ($id): string => (string) $id)
                ->sort()
                ->values()
                ->all();
            $groupIds = $this->groupIds($data);
            $groups = $this->lockGroups($groupIds);
            $attributes = $this->eventAttributes($data);
            $attributes['audience_scope'] = $this->audienceScope($groups);

            $requestedMaximum = $attributes['max_participants'] ?? null;
            $participantCount = $this->events->participantCount($locked);
            if (
                $requestedMaximum !== null
                && (int) $requestedMaximum < $participantCount
            ) {
                throw new CalendarEventConflictException(
                    'calendar_capacity_below_participant_count',
                    'Het maximumaantal deelnemers kan niet lager zijn dan het huidige aantal inschrijvingen.',
                    [
                        'participant_count' => $participantCount,
                        'max_participants' => (int) $requestedMaximum,
                    ],
                );
            }

            $locked->fill($attributes);
            $changedFields = array_values(array_intersect(
                self::MUTABLE_FIELDS,
                array_keys($locked->getDirty()),
            ));
            $groupsChanged = $beforeGroupIds !== $groupIds;
            if ($changedFields === [] && ! $groupsChanged) {
                return $this->events->forPresentation((string) $locked->id, $actor);
            }

            $locked->updated_by = $actor->id;
            $locked->save();
            $locked->audienceGroups()->sync($groupIds);
            $this->audit->record(
                'calendar_events.updated',
                $locked,
                $actor,
                [
                    'changed_fields' => $changedFields,
                    'group_ids_before' => $beforeGroupIds,
                    'group_ids_after' => $groupIds,
                    'participant_count' => $participantCount,
                    'registration_enabled' => (bool) $locked->registration_enabled,
                    'max_participants' => $locked->max_participants,
                ],
                null,
                $request,
            );

            return $this->events->forPresentation((string) $locked->id, $actor);
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
                [
                    'group_ids' => $locked->audienceGroups()
                        ->pluck('calendar_groups.id')
                        ->map(static fn ($id): string => (string) $id)
                        ->values()
                        ->all(),
                    'registration_enabled' => (bool) $locked->registration_enabled,
                    'max_participants' => $locked->max_participants,
                ],
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

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function groupIds(array $data): array
    {
        return collect($data['group_ids'] ?? [])
            ->map(static fn (mixed $id): string => (string) $id)
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $groupIds
     * @return Collection<int, CalendarGroup>
     */
    private function lockGroups(array $groupIds): Collection
    {
        $groups = CalendarGroup::query()
            ->whereIn('id', $groupIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'is_everyone']);

        if ($groupIds === [] || $groups->count() !== count($groupIds)) {
            throw ValidationException::withMessages([
                'group_ids' => ['Ieder agenda-item moet aan ten minste één bestaande agendagroep zijn gekoppeld.'],
            ]);
        }

        return $groups;
    }

    /** @param Collection<int, CalendarGroup> $groups */
    private function audienceScope(Collection $groups): string
    {
        return $groups->contains(
            static fn (CalendarGroup $group): bool => $group->is_everyone,
        )
            ? CalendarEvent::AUDIENCE_SCOPE_EVERYONE
            : CalendarEvent::AUDIENCE_SCOPE_GROUPS;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function eventAttributes(array $data): array
    {
        unset(
            $data['group_ids'],
            $data['team_id'],
            $data['audience_scope'],
        );

        return $data;
    }
}
