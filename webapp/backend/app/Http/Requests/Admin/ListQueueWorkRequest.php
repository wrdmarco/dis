<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListQueueWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('system.health.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'queue' => ['sometimes', 'string', Rule::in(['all', 'push', 'speech'])],
            'state' => ['sometimes', 'string', Rule::in([
                'all',
                'pending',
                'queued',
                'processing',
                'retrying',
                'failed',
                'completed',
                'cancelled',
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:2000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $perPage = max(1, min(100, (int) $this->input('per_page', 50)));
                    if ((((int) $value) - 1) * $perPage >= 2000) {
                        $fail('De opgevraagde queuepagina valt buiten het veilige venster van 2000 regels.');
                    }
                },
            ],
        ];
    }

    /** @return array{queue:string,state:string,per_page:int,page:int} */
    public function filters(): array
    {
        return [
            'queue' => (string) $this->validated('queue', 'all'),
            'state' => (string) $this->validated('state', 'all'),
            'per_page' => (int) $this->validated('per_page', 50),
            'page' => (int) $this->validated('page', 1),
        ];
    }
}
