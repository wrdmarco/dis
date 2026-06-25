<?php

namespace App\Http\Requests\Users;

use App\Services\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

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
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', app(PasswordPolicy::class)->rule()],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'account_status' => ['nullable', 'in:active,suspended,blocked'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['ulid', 'exists:roles,id'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['ulid', 'exists:teams,id'],
        ];
    }
}
