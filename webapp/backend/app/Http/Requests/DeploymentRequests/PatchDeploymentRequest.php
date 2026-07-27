<?php

namespace App\Http\Requests\DeploymentRequests;

use Illuminate\Foundation\Http\FormRequest;

final class PatchDeploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('deployments.manage') === true;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'client_mutation_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'changes' => ['required', 'array:title,subject_type,answers', 'min:1'],
            'changes.title' => ['sometimes', 'string', 'min:1', 'max:180'],
            'changes.subject_type' => ['sometimes', 'string', 'in:person,animal,object'],
            'changes.answers' => ['sometimes', 'array', 'max:100'],
        ];
    }
}
