<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListCalendarEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('calendar.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date'],
            'until' => [
                'sometimes',
                'date',
                Rule::when($this->filled('from'), ['after_or_equal:from']),
            ],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
