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
            'deployment_id' => ['nullable', 'required_without:user_id', 'prohibits:user_id', 'ulid', 'exists:deployments,id'],
            'user_id' => ['nullable', 'required_without:deployment_id', 'prohibits:deployment_id', 'ulid', 'exists:users,id'],
        ];
    }
}
