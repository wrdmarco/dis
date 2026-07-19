<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWallboardMediaFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
            'parent_id' => ['nullable', 'string', 'ulid', 'exists:wallboard_media_folders,id'],
        ];
    }
}
