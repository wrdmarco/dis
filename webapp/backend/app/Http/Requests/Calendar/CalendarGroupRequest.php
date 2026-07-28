<?php

namespace App\Http\Requests\Calendar;

use App\Services\WebSessionService;
use Illuminate\Foundation\Http\FormRequest;

abstract class CalendarGroupRequest extends FormRequest
{
    public function authorize(WebSessionService $webSessionService): bool
    {
        $webSessionService->assertStatefulWebRequest($this);

        return $this->user()?->hasPermission('calendar.view') === true
            && $this->user()?->hasPermission('calendar.groups.manage') === true;
    }
}
