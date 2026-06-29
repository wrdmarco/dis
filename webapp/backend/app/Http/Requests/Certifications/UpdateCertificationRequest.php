<?php

namespace App\Http\Requests\Certifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('certifications.manage') === true;
    }

    public function rules(): array
    {
        $certificationId = (string) $this->route('certification')?->getKey();

        return [
            'code' => ['sometimes', 'string', 'max:80', Rule::unique('certifications', 'code')->ignore($certificationId)],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'is_required_for_dispatch' => ['sometimes', 'boolean'],
        ];
    }
}
