<?php

namespace App\Http\Requests\Admin;

final class StoreWallboardPlaylistRequest extends WallboardPlaylistRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            ...$this->configurationRules('required'),
        ];
    }
}
