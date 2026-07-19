<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateWallboardMediaFolderRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:wallboard_media_folders,id'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('name') && ! $this->has('parent_id')) {
                $validator->errors()->add('folder', 'Geef een naam of bovenliggende map op.');
            }
        }];
    }
}
