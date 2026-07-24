<?php

namespace App\Http\Requests\Admin;

use App\Services\WebSessionService;
use Illuminate\Foundation\Http\FormRequest;

final class ManageQueueWorkRequest extends FormRequest
{
    public function authorize(WebSessionService $webSessionService): bool
    {
        $webSessionService->assertStatefulWebRequest($this);

        return $this->user()?->hasPermission('system.queues.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
