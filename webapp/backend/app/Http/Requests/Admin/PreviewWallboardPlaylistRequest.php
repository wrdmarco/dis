<?php

namespace App\Http\Requests\Admin;

final class PreviewWallboardPlaylistRequest extends WallboardPlaylistRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'data_mode' => $this->dataModeRules(),
            ...$this->configurationRules('required'),
        ];
    }
}
