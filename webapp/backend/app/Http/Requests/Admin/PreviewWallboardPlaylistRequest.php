<?php

namespace App\Http\Requests\Admin;

final class PreviewWallboardPlaylistRequest extends WallboardPlaylistRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->configurationRules('required');
    }
}
