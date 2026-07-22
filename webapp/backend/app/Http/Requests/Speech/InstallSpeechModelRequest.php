<?php

namespace App\Http\Requests\Speech;

use Illuminate\Foundation\Http\FormRequest;

final class InstallSpeechModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'license_confirmed' => ['required', 'accepted'],
            'revision' => ['prohibited'],
            'weights_sha256' => ['prohibited'],
            'source' => ['prohibited'],
            'url' => ['prohibited'],
        ];
    }
}
