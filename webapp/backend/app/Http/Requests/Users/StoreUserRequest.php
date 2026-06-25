<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('users.manage') === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(14)->mixedCase()->numbers()->symbols()->uncompromised()],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'account_status' => ['nullable', 'in:active,suspended,blocked'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['ulid', 'exists:roles,id'],
        ];
    }
}
