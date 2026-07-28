<?php

namespace App\Services;

use App\Exceptions\CalendarEventConflictException;
use App\Models\CalendarGroup;
use App\Models\Team;
use App\Models\User;
use App\Repositories\CalendarGroupRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CalendarGroupService
{
    public function __construct(
        private readonly CalendarGroupRepository $groups,
        private readonly AuditService $audit,
    ) {}

    /** @return Collection<int, CalendarGroup> */
    public function all(User $actor): Collection
    {
        $this->authorize($actor);

        return $this->groups->allForManagement();
    }

    public function show(CalendarGroup $group, User $actor): CalendarGroup
    {
        $this->authorize($actor);

        return $this->groups->forPresentation((string) $group->id);
    }

    /**
     * @return array{users: Collection<int, User>, teams: Collection<int, Team>}
     */
    public function memberOptions(User $actor, ?string $search): array
    {
        $this->authorize($actor);

        return $this->groups->memberOptions($search);
    }

    /** @return Collection<int, CalendarGroup> */
    public function eventOptions(User $actor): Collection
    {
        if (
            ! $actor->hasPermission('calendar.view')
            || ! $actor->hasPermission('calendar.manage')
        ) {
            throw new AuthorizationException;
        }

        return $this->groups->options();
    }

    /** @param array<string, mixed> $data */
    public function create(
        array $data,
        User $actor,
        Request $request,
    ): CalendarGroup {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $actor, $request): CalendarGroup {
            $userIds = $this->ids($data['user_ids'] ?? []);
            $teamIds = $this->ids($data['team_ids'] ?? []);
            $this->assertMembersAvailable($userIds, $teamIds);
            unset($data['user_ids'], $data['team_ids']);

            $created = $this->groups->create($data + [
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            if (! $created instanceof CalendarGroup) {
                throw new \LogicException('Calendar group repository returned an unexpected model.');
            }

            $this->syncMembership($created, $userIds, $teamIds, $actor);
            $this->audit->record(
                'calendar_groups.created',
                $created,
                $actor,
                [
                    'direct_user_ids' => $userIds,
                    'team_ids' => $teamIds,
                ],
                null,
                $request,
            );

            return $this->groups->forPresentation((string) $created->id);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(
        CalendarGroup $group,
        array $data,
        User $actor,
        Request $request,
    ): CalendarGroup {
        $this->authorize($actor);

        return DB::transaction(function () use ($group, $data, $actor, $request): CalendarGroup {
            $locked = $this->groups->lock((string) $group->id);
            $this->assertMutable($locked);
            $beforeUserIds = $locked->directUsers()
                ->pluck('users.id')
                ->map(static fn ($id): string => (string) $id)
                ->sort()
                ->values()
                ->all();
            $beforeTeamIds = $locked->teams()
                ->pluck('teams.id')
                ->map(static fn ($id): string => (string) $id)
                ->sort()
                ->values()
                ->all();
            $userIds = array_key_exists('user_ids', $data)
                ? $this->ids($data['user_ids'])
                : $beforeUserIds;
            $teamIds = array_key_exists('team_ids', $data)
                ? $this->ids($data['team_ids'])
                : $beforeTeamIds;
            $this->assertMembersAvailable($userIds, $teamIds);
            unset($data['user_ids'], $data['team_ids']);

            $locked->fill($data);
            $changedFields = array_keys($locked->getDirty());
            $membershipChanged = $beforeUserIds !== $userIds || $beforeTeamIds !== $teamIds;
            if ($changedFields === [] && ! $membershipChanged) {
                return $this->groups->forPresentation((string) $locked->id);
            }

            $locked->updated_by = $actor->id;
            $locked->save();
            if ($membershipChanged) {
                $this->syncMembership($locked, $userIds, $teamIds, $actor);
            }
            $this->audit->record(
                'calendar_groups.updated',
                $locked,
                $actor,
                [
                    'changed_fields' => $changedFields,
                    'direct_user_ids_before' => $beforeUserIds,
                    'direct_user_ids_after' => $userIds,
                    'team_ids_before' => $beforeTeamIds,
                    'team_ids_after' => $teamIds,
                ],
                null,
                $request,
            );

            return $this->groups->forPresentation((string) $locked->id);
        }, 3);
    }

    public function delete(
        CalendarGroup $group,
        User $actor,
        Request $request,
    ): void {
        $this->authorize($actor);

        DB::transaction(function () use ($group, $actor, $request): void {
            $locked = $this->groups->lock((string) $group->id);
            $this->assertMutable($locked);
            if ($locked->calendarEvents()->withTrashed()->exists()) {
                throw new CalendarEventConflictException(
                    'calendar_group_in_use',
                    'Deze agendagroep is nog aan een of meer agenda-items gekoppeld.',
                );
            }

            $this->audit->record(
                'calendar_groups.deleted',
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
        if (
            ! $actor->hasPermission('calendar.view')
            || ! $actor->hasPermission('calendar.groups.manage')
        ) {
            throw new AuthorizationException;
        }
    }

    private function assertMutable(CalendarGroup $group): void
    {
        if (! $group->is_everyone) {
            return;
        }

        throw new CalendarEventConflictException(
            'calendar_group_system_protected',
            'De systeemgroep Iedereen kan niet worden gewijzigd of verwijderd.',
        );
    }

    /**
     * @param  list<string>  $userIds
     * @param  list<string>  $teamIds
     */
    private function assertMembersAvailable(array $userIds, array $teamIds): void
    {
        $availableUserCount = User::query()
            ->withoutStoreReview()
            ->whereIn('id', $userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id'])
            ->count();
        $availableTeamCount = Team::query()
            ->whereIn('id', $teamIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id'])
            ->count();

        if (
            $availableUserCount === count($userIds)
            && $availableTeamCount === count($teamIds)
        ) {
            return;
        }

        throw ValidationException::withMessages([
            'members' => ['Een of meer geselecteerde gebruikers of teams zijn niet meer beschikbaar.'],
        ]);
    }

    /**
     * @param  list<string>  $userIds
     * @param  list<string>  $teamIds
     */
    private function syncMembership(
        CalendarGroup $group,
        array $userIds,
        array $teamIds,
        User $actor,
    ): void {
        $now = now();
        $group->directUsers()->sync(collect($userIds)->mapWithKeys(
            fn (string $id): array => [$id => [
                'assigned_by' => $actor->id,
                'created_at' => $now,
            ]],
        )->all());
        $group->teams()->sync(collect($teamIds)->mapWithKeys(
            fn (string $id): array => [$id => [
                'assigned_by' => $actor->id,
                'created_at' => $now,
            ]],
        )->all());
    }

    /** @return list<string> */
    private function ids(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
