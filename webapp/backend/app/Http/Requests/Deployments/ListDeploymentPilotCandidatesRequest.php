<?php

namespace App\Http\Requests\Deployments;

use Illuminate\Foundation\Http\FormRequest;

final class ListDeploymentPilotCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('deployments.dispatch.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
