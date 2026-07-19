<?php

namespace App\Http\Requests\Admin;

use App\Services\WallboardMediaPlaylistService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateWallboardMediaPlaylistRequest extends FormRequest
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
            'asset_ids' => ['sometimes', 'required', 'array', 'min:1', 'max:'.WallboardMediaPlaylistService::MAX_ITEMS],
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

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('name') && ! $this->has('asset_ids')) {
                $validator->errors()->add('playlist', 'Geef een naam of lijst met afbeeldingen op.');
            }
        }];
    }
}
