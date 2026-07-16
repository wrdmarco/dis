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
            // Older admin clients still submit this field. Validate its legacy
            // shape for compatibility, but never use it as operational input.
            'health_coordinate' => ['sometimes', 'array:longitude,latitude'],
            'health_coordinate.longitude' => ['required_with:health_coordinate', 'numeric', 'between:-180,180'],
            'health_coordinate.latitude' => ['required_with:health_coordinate', 'numeric', 'between:-90,90'],
        ];
    }
}
