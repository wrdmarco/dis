<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class SetWallboardDisplayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page_id' => ['present', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'expected_control_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
