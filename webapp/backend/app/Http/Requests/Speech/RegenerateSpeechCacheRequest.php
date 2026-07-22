<?php

namespace App\Http\Requests\Speech;

use Illuminate\Foundation\Http\FormRequest;

final class RegenerateSpeechCacheRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['scope' => ['required', 'in:all,segments,composites,failed']];
    }
}
