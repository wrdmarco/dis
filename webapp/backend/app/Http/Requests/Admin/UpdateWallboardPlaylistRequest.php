<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Validator;

final class UpdateWallboardPlaylistRequest extends WallboardPlaylistRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            ...$this->configurationRules('sometimes'),
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if (! $this->has('name') && ! $this->has('configuration')) {
                    $validator->errors()->add('playlist', 'Geef een naam of configuratiewijziging op.');
                }
            },
        ];
    }
}
