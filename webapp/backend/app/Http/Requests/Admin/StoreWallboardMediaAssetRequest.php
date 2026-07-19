<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWallboardMediaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maximum = max(
            1,
            (int) config('wallboard_media.max_upload_kilobytes', 15 * 1024),
            (int) config('wallboard_media.max_video_upload_kilobytes', 250 * 1024),
        );

        return [
            'file' => [
                'required_without:image',
                Rule::prohibitedIf(fn (): bool => $this->hasFile('image')),
                'file',
                'max:'.$maximum,
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4',
            ],
            'image' => [
                'required_without:file',
                Rule::prohibitedIf(fn (): bool => $this->hasFile('file')),
                'file',
                'max:'.max(1, (int) config('wallboard_media.max_upload_kilobytes', 15 * 1024)),
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
            'folder_id' => ['nullable', 'string', 'ulid', 'exists:wallboard_media_folders,id'],
            'display_name' => ['nullable', 'string', 'max:180', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
        ];
    }
}
