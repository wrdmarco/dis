<?php

namespace App\Http\Requests\Admin;

use App\Models\WallboardPlaylist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignWallboardPlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'playlist_id' => [
                'required',
                'ulid',
                Rule::exists('wallboard_playlists', 'id')
                    ->where('purpose', WallboardPlaylist::PURPOSE_NORMAL),
            ],
            'expected_config_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
