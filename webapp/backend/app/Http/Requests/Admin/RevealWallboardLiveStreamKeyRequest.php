<?php

namespace App\Http\Requests\Admin;

use App\Services\WebSessionService;
use Illuminate\Foundation\Http\FormRequest;

final class RevealWallboardLiveStreamKeyRequest extends FormRequest
{
    public function authorize(WebSessionService $webSessionService): bool
    {
        $webSessionService->assertStatefulWebRequest($this);

        return $this->user()?->hasPermission('wallboards.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stream_key' => ['prohibited'],
        ];
    }
}
