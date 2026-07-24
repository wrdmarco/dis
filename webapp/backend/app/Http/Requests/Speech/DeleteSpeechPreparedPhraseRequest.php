<?php

namespace App\Http\Requests\Speech;

use Illuminate\Foundation\Http\FormRequest;

final class DeleteSpeechPreparedPhraseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') === true
            && $this->user()?->hasPermission('speech.cache.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
