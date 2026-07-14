<?php

namespace App\Http\Requests\Dispatch;

use App\Models\DispatchRequest;
use Illuminate\Foundation\Http\FormRequest;

final class RespondDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $dispatch = $this->route('dispatch');

        if ($user === null || ! $dispatch instanceof DispatchRequest) {
            return false;
        }

        $canRespond = $user->hasPermission('incidents.assigned.view')
            || $user->hasPermission('incidents.dispatch.view')
            || $user->hasPermission('incidents.dispatch.manage');

        return $canRespond
            && $dispatch->recipients()->where('user_id', $user->id)->exists();
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'in:accepted,declined'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
