<?php

namespace App\Http\Requests\Dispatch;

use Illuminate\Foundation\Http\FormRequest;

final class RespondDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incidents.dispatch.view') === true;
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'in:accepted,declined'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
