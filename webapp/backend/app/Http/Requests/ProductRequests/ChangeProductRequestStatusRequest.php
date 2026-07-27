<?php

namespace App\Http\Requests\ProductRequests;

use App\Models\ProductRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ChangeProductRequestStatusRequest extends ProductRequestFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStringInputs(['status', 'resolution_note']);
    }

    public function authorize(): bool
    {
        return $this->webUserHasPermissions(
            'product-requests.view',
            'product-requests.resolve',
        );
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ProductRequest::STATUSES)],
            'resolution_note' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'type' => ['prohibited'],
            'title' => ['prohibited'],
            'description' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'requester_name_snapshot' => ['prohibited'],
            'resolved_by' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $targetStatus = $this->input('status');
            $productRequest = $this->route('productRequest');
            $reopening = $productRequest instanceof ProductRequest
                && $productRequest->isTerminal()
                && $targetStatus === 'open';

            if (
                (in_array($targetStatus, ProductRequest::TERMINAL_STATUSES, true) || $reopening)
                && trim((string) $this->input('resolution_note')) === ''
            ) {
                $validator->errors()->add(
                    'resolution_note',
                    'Een toelichting is verplicht voor deze statuswijziging.',
                );
            }
        }];
    }
}
