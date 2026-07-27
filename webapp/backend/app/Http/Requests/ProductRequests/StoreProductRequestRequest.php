<?php

namespace App\Http\Requests\ProductRequests;

use App\Models\ProductRequest;
use Illuminate\Validation\Rule;

final class StoreProductRequestRequest extends ProductRequestFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStringInputs(['type', 'title', 'description']);
    }

    public function authorize(): bool
    {
        return $this->webUserHasPermissions(
            'product-requests.view',
            'product-requests.create',
        );
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(ProductRequest::TYPES)],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:20000'],
            'requester_id' => ['prohibited'],
            'requester_name_snapshot' => ['prohibited'],
            'status' => ['prohibited'],
            'resolution_note' => ['prohibited'],
            'resolved_by' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'lock_version' => ['prohibited'],
        ];
    }
}
