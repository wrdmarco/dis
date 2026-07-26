<?php

namespace App\Http\Requests\IncidentIntakes;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateIntakeWorkflowDraftRequest extends FormRequest
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
