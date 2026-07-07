<?php

namespace App\Http\Requests\Dispatch;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incidents.dispatch.manage') === true;
    }

    public function rules(): array
    {
        return [
            'priority' => ['required', 'in:normal,high,critical'],
            'message' => ['required', 'string', 'max:2000'],
            'target_team_id' => ['nullable', 'ulid', 'exists:teams,id'],
            'team_code' => ['nullable', 'in:OCP,TUI'],
            'include_unavailable' => ['sometimes', 'boolean'],
            'dispatch_recipient_count' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
