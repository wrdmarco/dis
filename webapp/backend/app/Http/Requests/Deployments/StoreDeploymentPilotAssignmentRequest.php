<?php

namespace App\Http\Requests\Deployments;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDeploymentPilotAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('deployments.dispatch.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'ulid', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
