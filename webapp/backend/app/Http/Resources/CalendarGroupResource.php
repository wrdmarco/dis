<?php

namespace App\Http\Resources;

use App\Models\CalendarGroup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CalendarGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CalendarGroup $group */
        $group = $this->resource;

        return [
            'id' => (string) $group->id,
            'name' => (string) $group->name,
            'description' => $group->description,
            'is_everyone' => (bool) $group->is_everyone,
            'direct_users' => $group->relationLoaded('directUsers')
                ? $group->directUsers
                    ->map(static fn (User $user): array => [
                        'id' => (string) $user->id,
                        'name' => (string) $user->name,
                        'email' => (string) $user->email,
                    ])
                    ->values()
                    ->all()
                : [],
            'teams' => $group->relationLoaded('teams')
                ? $group->teams
                    ->map(static fn (Team $team): array => [
                        'id' => (string) $team->id,
                        'code' => (string) $team->code,
                        'name' => (string) $team->name,
                    ])
                    ->values()
                    ->all()
                : [],
            'direct_user_count' => (int) ($group->getAttribute('direct_user_count') ?? 0),
            'team_count' => (int) ($group->getAttribute('team_count') ?? 0),
            'effective_member_count' => (int) ($group->getAttribute('effective_member_count') ?? 0),
        ];
    }
}
