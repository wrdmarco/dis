<?php

namespace App\Http\Requests\IncidentIntakes;

use Illuminate\Foundation\Http\FormRequest;

final class IntakeMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incidents.manage') === true;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'client_mutation_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
