<?php

namespace App\Http\Requests\DeploymentRequests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDeploymentRequestWorkflowDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('forms.manage') === true;
    }

    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'configuration' => ['required', 'array'],
        ];
    }
}
