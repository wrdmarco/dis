<?php

namespace App\Http\Requests\Admin;

use App\Models\OsrmOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartOsrmOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $sha256 = $this->input('source_sha256');
        if (is_string($sha256)) {
            $this->merge(['source_sha256' => strtolower(trim($sha256))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in([
                OsrmOperation::ACTION_INSTALL_ACTIVATE,
                OsrmOperation::ACTION_UPDATE,
            ])],
            'source_sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'source_url' => ['prohibited'],
            'health_coordinate' => [
                'required_if:action,'.OsrmOperation::ACTION_INSTALL_ACTIVATE,
                'prohibited_if:action,'.OsrmOperation::ACTION_UPDATE,
                'array:longitude,latitude',
            ],
            'health_coordinate.longitude' => [
                'required_if:action,'.OsrmOperation::ACTION_INSTALL_ACTIVATE,
                'numeric',
                'between:-180,180',
            ],
            'health_coordinate.latitude' => [
                'required_if:action,'.OsrmOperation::ACTION_INSTALL_ACTIVATE,
                'numeric',
                'between:-90,90',
            ],
        ];
    }
}
