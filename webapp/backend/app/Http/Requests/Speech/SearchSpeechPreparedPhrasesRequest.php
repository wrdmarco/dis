<?php

namespace App\Http\Requests\Speech;

use App\Models\SpeechPreparedPhrase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchSpeechPreparedPhrasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') === true
            && $this->user()?->hasPermission('speech.cache.view') === true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['search', 'kind', 'status'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $this->merge([$key => trim($value) !== '' ? trim($value) : null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'kind' => ['nullable', 'string', Rule::in(SpeechPreparedPhrase::KINDS)],
            'status' => ['nullable', 'string', Rule::in(SpeechPreparedPhrase::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
