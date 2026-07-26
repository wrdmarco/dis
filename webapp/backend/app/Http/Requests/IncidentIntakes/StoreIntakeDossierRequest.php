<?php

namespace App\Http\Requests\IncidentIntakes;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIntakeDossierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incidents.manage') === true;
    }

    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', 'in:person,animal,object'],
            'answers' => ['sometimes', 'array', 'max:100'],
            'client_mutation_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
