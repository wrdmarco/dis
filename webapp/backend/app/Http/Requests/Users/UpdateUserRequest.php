<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('users.manage') === true;
    }

    public function rules(): array
    {
        $userId = (string) $this->route('user')?->getKey();

        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'email' => ['sometimes', 'email:rfc,dns', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'account_status' => ['sometimes', 'in:active,suspended,blocked'],
        ];
    }
}

