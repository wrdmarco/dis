<?php

namespace App\Http\Requests\Speech;

use Illuminate\Foundation\Http\FormRequest;

final class CreateSpeechPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phase' => ['required', 'in:availability,attendance,test_ack'],
            'text' => ['prohibited'],
        ];
    }
}
