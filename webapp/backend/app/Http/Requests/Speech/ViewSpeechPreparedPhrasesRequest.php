<?php

namespace App\Http\Requests\Speech;

use Illuminate\Foundation\Http\FormRequest;

final class ViewSpeechPreparedPhrasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') === true
            && $this->user()?->hasPermission('speech.cache.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
