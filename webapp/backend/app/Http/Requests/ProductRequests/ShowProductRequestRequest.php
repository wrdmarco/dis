<?php

namespace App\Http\Requests\ProductRequests;

final class ShowProductRequestRequest extends ProductRequestFormRequest
{
    public function authorize(): bool
    {
        return $this->webUserHasPermissions('product-requests.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
