<?php

namespace App\Http\Requests\ProductRequests;

final class IndexProductRequestsRequest extends ProductRequestFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStringInputs(['search']);
    }

    public function authorize(): bool
    {
        return $this->webUserHasPermissions('product-requests.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => [
                'sometimes',
                'string',
                'regex:/^(open|in_progress|resolved|rejected)(,(open|in_progress|resolved|rejected))*$/',
            ],
            'type' => [
                'sometimes',
                'string',
                'regex:/^(feature|change|bug)(,(feature|change|bug))*$/',
            ],
            'search' => ['sometimes', 'string', 'min:1', 'max:120'],
            'mine' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return $this->csv('status');
    }

    /** @return list<string> */
    public function types(): array
    {
        return $this->csv('type');
    }

    public function onlyMine(): bool
    {
        return $this->boolean('mine');
    }

    public function searchTerm(): ?string
    {
        $search = $this->validated('search');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 25);
    }

    /** @return list<string> */
    private function csv(string $key): array
    {
        $value = $this->validated($key);
        if (! is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_unique(explode(',', $value)));
    }
}
