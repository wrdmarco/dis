<?php

namespace App\Http\Requests\Users;

use App\Services\PasswordPolicy;
use App\Support\ProfileLocation;
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
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', app(PasswordPolicy::class)->rule()],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'home_city' => ['nullable', 'string', 'max:120'],
            'home_region' => ['nullable', 'string', 'max:120'],
            'home_country' => ['nullable', 'string', 'size:2', Rule::in(ProfileLocation::countryCodes())],
            'account_status' => ['sometimes', 'in:active,suspended,blocked'],
            'max_operator_devices' => ['sometimes', 'integer', 'min:1', 'max:20'],
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
