<?php

namespace App\Http\Requests\ProductRequests;

use App\Services\WebSessionService;
use Illuminate\Foundation\Http\FormRequest;

abstract class ProductRequestFormRequest extends FormRequest
{
    protected function webUserHasPermissions(string ...$permissions): bool
    {
        $user = $this->user();
        if ($user === null || ! app(WebSessionService::class)->isStatefulWebRequest($this)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (! $user->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $keys */
    protected function trimStringInputs(array $keys): void
    {
        $normalized = [];
        foreach ($keys as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
