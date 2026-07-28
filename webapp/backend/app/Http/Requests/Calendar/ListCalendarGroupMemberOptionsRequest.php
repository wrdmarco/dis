<?php

namespace App\Http\Requests\Calendar;

final class ListCalendarGroupMemberOptionsRequest extends CalendarGroupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
