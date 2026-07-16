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
            'source_url' => ['prohibited'],
            'source_md5' => ['prohibited'],
            'source_sha256' => ['prohibited'],
            'sources' => ['prohibited'],
            'source_set' => ['prohibited'],
            'source_manifest' => ['prohibited'],
            'source_set_sha256' => ['prohibited'],
            'snapshot_date' => ['prohibited'],
            'source_timestamp' => ['prohibited'],
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
