<?php

namespace App\Http\Requests\Calendar;

use App\Services\WebSessionService;
use Illuminate\Foundation\Http\FormRequest;

final class ManageCalendarRegistrationRequest extends FormRequest
{
    public function authorize(WebSessionService $webSessionService): bool
    {
        $webSessionService->assertStatefulWebRequest($this);

        return $this->user()?->hasPermission('calendar.view') === true
            && $this->user()?->hasPermission('calendar.registrations.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
