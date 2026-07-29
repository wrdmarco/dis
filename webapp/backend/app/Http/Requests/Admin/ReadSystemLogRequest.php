<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ReadSystemLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('system.logs.view') === true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['generation', 'checkpoint'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $this->merge([
                $field => is_string($value)
                    ? strtolower(trim($value))
                    : $value,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'cursor' => ['sometimes', 'integer', 'min:0', 'max:9007199254740991'],
            'generation' => [
                'required_with:cursor',
                'string',
                'size:64',
                'regex:/\A[a-f0-9]{64}\z/D',
            ],
            'checkpoint' => [
                'required_with:cursor',
                'string',
                'size:64',
                'regex:/\A[a-f0-9]{64}\z/D',
            ],
        ];
    }

    /** @return array{lines: int, cursor: int|null, generation: string|null, checkpoint: string|null} */
    public function parameters(): array
    {
        return [
            'lines' => (int) $this->validated('lines', 200),
            'cursor' => $this->has('cursor') ? (int) $this->validated('cursor') : null,
            'generation' => $this->validated('generation'),
            'checkpoint' => $this->validated('checkpoint'),
        ];
    }
}
