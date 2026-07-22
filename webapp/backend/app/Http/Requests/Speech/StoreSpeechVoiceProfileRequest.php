<?php

namespace App\Http\Requests\Speech;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSpeechVoiceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'locale' => ['required', 'in:nl-NL'],
            'transcript' => ['required', 'string', 'max:2000'],
            'consent_confirmed' => ['required', 'accepted'],
            'audio' => ['required', 'file', 'max:32768'],
        ];
    }
}
