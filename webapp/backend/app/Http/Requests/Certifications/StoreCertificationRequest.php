<?php

namespace App\Http\Requests\Certifications;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('certifications.manage') === true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80', 'unique:certifications,code'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'is_required_for_dispatch' => ['required', 'boolean'],
        ];
    }
}
