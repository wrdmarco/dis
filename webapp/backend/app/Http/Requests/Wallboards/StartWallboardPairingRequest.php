<?php

namespace App\Http\Requests\Wallboards;

use Illuminate\Foundation\Http\FormRequest;

final class StartWallboardPairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
