<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexKnmiCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $query = $this->input('query');
        $status = $this->input('status');
        $license = $this->input('license');

        $this->merge([
            'query' => is_string($query) && trim($query) !== '' ? trim($query) : null,
            'status' => is_string($status) && trim($status) !== ''
                ? strtolower(trim($status))
                : null,
            'license' => is_string($license) && trim($license) !== '' ? trim($license) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', 'string', Rule::in(['ongoing', 'completed'])],
            'license' => [
                'nullable',
                'string',
                'max:80',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9.+_-]*\z/',
            ],
        ];
    }
}
