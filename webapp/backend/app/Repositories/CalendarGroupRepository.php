<?php

namespace App\Repositories;

use App\Models\CalendarGroup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<CalendarGroup>
 */
final class CalendarGroupRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return CalendarGroup::class;
    }

    /** @return Collection<int, CalendarGroup> */
    public function allForManagement(): Collection
    {
        return $this->markEffectiveCounts(
            $this->presentationQuery()
                ->orderBy('name')
                ->get(),
        );
    }

    public function forPresentation(string $id): CalendarGroup
    {
        return $this->markEffectiveCount(
            $this->presentationQuery()->findOrFail($id),
        );
    }

    public function lock(string $id): CalendarGroup
    {
        return $this->query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return Collection<int, CalendarGroup> */
    public function options(): Collection
    {
        return $this->markEffectiveCounts(
            $this->query()
                ->orderByDesc('is_everyone')
                ->orderBy('name')
                ->get(['id', 'name', 'is_everyone']),
        );
    }

    public function effectiveMemberCount(CalendarGroup $group): int
    {
        if ($group->is_everyone) {
            return User::query()->count();
        }

        $direct = DB::table('calendar_group_user')
            ->join('users', 'users.id', '=', 'calendar_group_user.user_id')
            ->select('calendar_group_user.user_id')
            ->whereNull('users.deleted_at')
            ->where('calendar_group_id', $group->id);
        $inherited = DB::table('team_user')
            ->join(
                'calendar_group_team',
                'calendar_group_team.team_id',
                '=',
                'team_user.team_id',
            )
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->join('users', 'users.id', '=', 'team_user.user_id')
            ->select('team_user.user_id')
            ->whereNull('teams.deleted_at')
            ->whereNull('users.deleted_at')
            ->where('calendar_group_team.calendar_group_id', $group->id);

        return DB::query()
            ->fromSub($direct->union($inherited), 'effective_calendar_group_users')
            ->count();
    }

    /**
     * @return array{users: Collection<int, User>, teams: Collection<int, Team>}
     */
    public function memberOptions(?string $search): array
    {
        $normalized = is_string($search) && trim($search) !== ''
            ? trim($search)
            : null;

        $users = User::query()
            ->withoutStoreReview()
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

        $teams = Team::query()
            ->when($normalized !== null, function (Builder $query) use ($normalized): void {
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($normalized));
                $pattern = '%'.$escaped.'%';
                $query->where(function (Builder $searchQuery) use ($pattern): void {
                    $searchQuery
                        ->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->orderBy('code')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'code', 'name']);

        return ['users' => $users, 'teams' => $teams];
    }

    /** @return Builder<CalendarGroup> */
    private function presentationQuery(): Builder
    {
        return $this->query()
            ->with([
                'directUsers' => fn ($users) => $users
                    ->orderBy('name')
                    ->select(['users.id', 'users.name', 'users.email']),
                'teams' => fn ($teams) => $teams
                    ->orderBy('code')
                    ->select(['teams.id', 'teams.code', 'teams.name']),
            ])
            ->orderByDesc('is_everyone')
            ->withCount([
                'directUsers as direct_user_count',
                'teams as team_count',
            ]);
    }

    private function markEffectiveCount(CalendarGroup $group): CalendarGroup
    {
        $group->setAttribute(
            'effective_member_count',
            $this->effectiveMemberCount($group),
        );

        return $group;
    }

    /**
     * @param  Collection<int, CalendarGroup>  $groups
     * @return Collection<int, CalendarGroup>
     */
    private function markEffectiveCounts(Collection $groups): Collection
    {
        $limitedGroupIds = $groups
            ->reject(static fn (CalendarGroup $group): bool => $group->is_everyone)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
        $counts = collect();

        if ($limitedGroupIds !== []) {
            $direct = DB::table('calendar_group_user')
                ->join('users', 'users.id', '=', 'calendar_group_user.user_id')
                ->select([
                    'calendar_group_user.calendar_group_id',
                    'calendar_group_user.user_id',
                ])
                ->whereNull('users.deleted_at')
                ->whereIn('calendar_group_user.calendar_group_id', $limitedGroupIds);
            $inherited = DB::table('calendar_group_team')
                ->join(
                    'team_user',
                    'team_user.team_id',
                    '=',
                    'calendar_group_team.team_id',
                )
                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                ->join('users', 'users.id', '=', 'team_user.user_id')
                ->select([
                    'calendar_group_team.calendar_group_id',
                    'team_user.user_id',
                ])
                ->whereNull('teams.deleted_at')
                ->whereNull('users.deleted_at')
                ->whereIn('calendar_group_team.calendar_group_id', $limitedGroupIds);

            $counts = DB::query()
                ->fromSub($direct->union($inherited), 'effective_calendar_group_users')
                ->selectRaw('calendar_group_id, COUNT(*) AS aggregate')
                ->groupBy('calendar_group_id')
                ->pluck('aggregate', 'calendar_group_id');
        }

        $everyoneCount = $groups->contains(
            static fn (CalendarGroup $group): bool => $group->is_everyone,
        )
            ? User::query()->count()
            : 0;

        return $groups->each(function (CalendarGroup $group) use ($counts, $everyoneCount): void {
            $group->setAttribute(
                'effective_member_count',
                $group->is_everyone
                    ? $everyoneCount
                    : (int) ($counts[(string) $group->id] ?? 0),
            );
        });
    }
}
