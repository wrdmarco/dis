<?php

namespace App\Http\Requests\TestAlerts;

use App\Services\TestAlertService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendTestAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incidents.dispatch.manage') === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => [
                'sometimes',
                'string',
                Rule::in([TestAlertService::SCOPE_SELF, TestAlertService::SCOPE_ALL_ONLINE]),
            ],
        ];
    }

    public function scope(): string
    {
        return (string) ($this->validated('scope') ?? TestAlertService::SCOPE_SELF);
    }
}
