<?php

namespace App\Http\Requests\Users;

use App\Services\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'password' => [Rule::requiredIf(! $this->boolean('send_welcome_mail')), 'nullable', app(PasswordPolicy::class)->rule()],
            'send_welcome_mail' => ['sometimes', 'boolean'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'home_city' => ['nullable', 'string', 'max:120'],
            'account_status' => ['nullable', 'in:active,suspended,blocked'],
            'max_operator_devices' => ['nullable', 'integer', 'min:1', 'max:20'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['ulid', 'exists:roles,id'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['ulid', 'exists:teams,id'],
            'mail_preferences' => ['nullable', 'array'],
            'mail_preferences.backup_report' => ['nullable', 'array'],
            'mail_preferences.backup_report.success' => ['sometimes', 'boolean'],
            'mail_preferences.backup_report.failed' => ['sometimes', 'boolean'],
        ];
    }
}
