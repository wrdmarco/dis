<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWallboardMediaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'max:'.max(1, (int) config('wallboard_media.max_upload_kilobytes', 15 * 1024)),
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
            'folder_id' => ['nullable', 'string', 'ulid', 'exists:wallboard_media_folders,id'],
            'display_name' => ['nullable', 'string', 'max:180', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
        ];
    }
}
