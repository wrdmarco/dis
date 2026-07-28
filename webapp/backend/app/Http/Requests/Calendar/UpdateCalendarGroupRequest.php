<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Validation\Rule;

final class UpdateCalendarGroupRequest extends CalendarGroupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'user_ids' => ['present', 'array'],
            'user_ids.*' => [
                'required',
                'ulid',
                'distinct',
                Rule::exists('users', 'id')
                    ->whereNull('deleted_at')
                    ->where(
                        static fn ($query) => $query
                            ->where('account_status', '!=', 'store_review'),
                    ),
            ],
            'team_ids' => ['present', 'array'],
            'team_ids.*' => [
                'required',
                'ulid',
                'distinct',
                Rule::exists('teams', 'id')->whereNull('deleted_at'),
            ],
            'is_everyone' => ['prohibited'],
            'legacy_team_id' => ['prohibited'],
        ];
    }
}
