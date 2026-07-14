<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateStoreReviewAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'password' => ['nullable', 'string', Password::min(24)->letters()->mixedCase()->numbers()->symbols(), 'max:255'],
            'platform' => ['sometimes', Rule::in(['apple', 'google'])],
        ];
    }
}
