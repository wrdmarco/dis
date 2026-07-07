<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class SendManualPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.push.manual.send') === true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1200'],
            'team_ids' => ['array'],
            'team_ids.*' => ['ulid', 'exists:teams,id'],
            'role_ids' => ['array'],
            'role_ids.*' => ['ulid', 'exists:roles,id'],
            'user_ids' => ['array'],
            'user_ids.*' => ['ulid', 'exists:users,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $recipientCount = count($this->input('team_ids', []))
                + count($this->input('role_ids', []))
                + count($this->input('user_ids', []));

            if ($recipientCount === 0) {
                $validator->errors()->add('recipients', 'Select at least one team, role or user.');
            }
        });
    }
}
