<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveWallboardPairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:16',
                'regex:/\A[A-HJ-NP-Z2-9]{4}[- ]?[A-HJ-NP-Z2-9]{4}\z/i',
            ],
        ];
    }
}
