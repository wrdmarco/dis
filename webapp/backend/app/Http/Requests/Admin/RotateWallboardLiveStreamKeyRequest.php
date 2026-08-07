<?php

namespace App\Http\Requests\Admin;

use App\Services\WebSessionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RotateWallboardLiveStreamKeyRequest extends FormRequest
{
    public const CONFIRMATION = 'WISSELEN';

    public function authorize(WebSessionService $webSessionService): bool
    {
        $webSessionService->assertStatefulWebRequest($this);

        return $this->user()?->hasPermission('wallboards.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in([self::CONFIRMATION])],
            'stream_key' => ['prohibited'],
            'expected_key_sha256' => ['prohibited'],
        ];
    }
}
