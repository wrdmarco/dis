<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

final class AssignAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('assets.manage') === true;
    }

    public function rules(): array
    {
        return [
            'incident_id' => ['nullable', 'ulid', 'exists:incidents,id'],
            'user_id' => ['nullable', 'ulid', 'exists:users,id'],
        ];
    }
}

