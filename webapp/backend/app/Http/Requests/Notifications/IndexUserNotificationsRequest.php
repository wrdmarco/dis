<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

final class IndexUserNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function notificationPage(): int
    {
        return (int) $this->validated('page', 1);
    }
}
