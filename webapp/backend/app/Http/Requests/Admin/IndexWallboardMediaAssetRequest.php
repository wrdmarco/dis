<?php

namespace App\Http\Requests\Admin;

use App\Models\WallboardMediaAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexWallboardMediaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'folder_id' => ['nullable', 'string', 'ulid', 'exists:wallboard_media_folders,id'],
            'unfiled' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'kind' => ['nullable', 'string', Rule::in([
                WallboardMediaAsset::KIND_IMAGE,
                WallboardMediaAsset::KIND_VIDEO,
            ])],
            'status' => ['nullable', 'string', Rule::in([
                WallboardMediaAsset::STATUS_PROCESSING,
                WallboardMediaAsset::STATUS_READY,
                WallboardMediaAsset::STATUS_FAILED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
