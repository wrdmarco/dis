<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateWallboardMediaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:wallboard_media_folders,id'],
            'display_name' => ['sometimes', 'required', 'string', 'max:180', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('folder_id') && ! $this->has('display_name')) {
                $validator->errors()->add('asset', 'Geef een map of afbeeldingsnaam op.');
            }
        }];
    }
}
