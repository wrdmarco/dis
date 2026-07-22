<?php

namespace App\Http\Requests\Speech;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSpeechSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'model_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            'voice_profile_id' => ['sometimes', 'nullable', 'ulid'],
            'speed' => ['sometimes', 'numeric', 'between:0.85,1.15'],
            'pre_generate_on_save' => ['sometimes', 'boolean'],
            'templates' => ['sometimes', 'array:availability,attendance,test_ack'],
            'templates.availability' => ['sometimes', 'array', 'min:1', 'max:8'],
            'templates.availability.*' => ['required', 'string', 'max:240'],
            'templates.attendance' => ['sometimes', 'array', 'min:1', 'max:8'],
            'templates.attendance.*' => ['required', 'string', 'max:240'],
            'templates.test_ack' => ['sometimes', 'array', 'min:1', 'max:8'],
            'templates.test_ack.*' => ['required', 'string', 'max:240'],
        ];
    }
}
