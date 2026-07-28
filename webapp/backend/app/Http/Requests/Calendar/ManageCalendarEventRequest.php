<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ManageCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('calendar.view') === true
            && $this->user()?->hasPermission('calendar.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'type' => ['required', 'string', Rule::in(['training', 'open_day', 'meeting', 'exercise', 'other'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location_label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'group_ids' => ['required', 'array', 'min:1'],
            'group_ids.*' => [
                'required',
                'ulid',
                'distinct',
                Rule::exists('calendar_groups', 'id')->whereNull('deleted_at'),
            ],
            'registration_enabled' => ['required', 'boolean'],
            'max_participants' => ['present', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'team_id' => ['prohibited'],
            'audience_scope' => ['prohibited'],
        ];
    }
}
