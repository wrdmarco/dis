<?php

namespace App\Http\Requests\Speech;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexSpeechCacheEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['search', 'category', 'status'] as $key) {
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $this->merge([
                $key => trim($value) !== '' ? trim($value) : null,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'category' => ['nullable', 'string', Rule::in(['segment', 'composite'])],
            'status' => ['nullable', 'string', Rule::in([
                'queued',
                'processing',
                'ready',
                'failed',
                'expired',
            ])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
