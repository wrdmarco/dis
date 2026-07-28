<?php

namespace App\Http\Requests\Calendar;

use App\Services\WebSessionService;
use Illuminate\Foundation\Http\FormRequest;

final class ViewCalendarRegistrationsRequest extends FormRequest
{
    public function authorize(WebSessionService $webSessionService): bool
    {
        $webSessionService->assertStatefulWebRequest($this);

        return $this->user()?->hasPermission('calendar.view') === true
            && $this->user()?->hasPermission('calendar.registrations.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
