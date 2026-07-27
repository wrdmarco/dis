<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

final class ListCalendarTeamOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('calendar.view') === true
            && $this->user()?->hasPermission('calendar.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
