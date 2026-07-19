<?php

namespace App\Http\Requests\Admin;

use App\Services\WallboardMediaPlaylistService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWallboardMediaPlaylistRequest extends FormRequest
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
            'asset_ids' => ['required', 'array', 'min:1', 'max:'.WallboardMediaPlaylistService::MAX_ITEMS],
            'asset_ids.*' => [
                'required',
                'string',
                'ulid',
                'distinct:strict',
                Rule::exists('wallboard_media_assets', 'id')
                    ->where('status', 'ready')
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
