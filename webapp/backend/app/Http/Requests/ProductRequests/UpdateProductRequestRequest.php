<?php

namespace App\Http\Requests\ProductRequests;

use App\Models\ProductRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProductRequestRequest extends ProductRequestFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStringInputs(['type', 'title', 'description']);
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $productRequest = $this->route('productRequest');

        if (
            $user === null
            || ! $this->webUserHasPermissions('product-requests.view')
            || ! $productRequest instanceof ProductRequest
        ) {
            return false;
        }

        return $user->hasPermission('product-requests.update-any')
            || (
                $user->hasPermission('product-requests.update-own')
                && $productRequest->isOwnedBy($user)
            );
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'type' => ['sometimes', 'required', 'string', Rule::in(ProductRequest::TYPES)],
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'required', 'string', 'max:20000'],
            'requester' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'requester_name_snapshot' => ['prohibited'],
            'created_by' => ['prohibited'],
            'status' => ['prohibited'],
            'resolution_note' => ['prohibited'],
            'resolved_by' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->exists('type') && ! $this->exists('title') && ! $this->exists('description')) {
                $validator->errors()->add(
                    'product_request',
                    'Geef minimaal één te wijzigen veld op.',
                );
            }
        }];
    }
}
