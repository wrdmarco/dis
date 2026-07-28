<?php

namespace App\Http\Requests\Assets;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('assets.manage') === true;
    }

    public function rules(): array
    {
        $asset = $this->route('asset');
        $assetId = $asset instanceof Asset ? (string) $asset->getKey() : '';

        return [
            'asset_tag' => ['sometimes', 'string', 'max:80', Rule::unique('assets', 'asset_tag')->ignore($assetId)],
            'name' => ['sometimes', 'string', 'max:160'],
            'type' => ['sometimes', 'in:drone,battery,sensor,vehicle,support_equipment'],
            'drone_type_id' => [Rule::requiredIf(fn (): bool => $this->requiresDroneType($asset)), 'nullable', 'ulid', 'exists:drone_types,id'],
            'has_spotlight' => ['sometimes', 'boolean'],
            'has_speaker' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:ready,assigned,maintenance,unavailable,retired'],
            'serial_number' => ['nullable', 'string', 'max:160', Rule::unique('assets', 'serial_number')->ignore($assetId)],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }

    private function requiresDroneType(mixed $asset): bool
    {
        $effectiveType = $this->input('type', $asset instanceof Asset ? $asset->type : null);
        if ($effectiveType !== 'drone') {
            return false;
        }

        return $this->exists('drone_type_id')
            || ! $asset instanceof Asset
            || $asset->type !== 'drone'
            || $asset->drone_type_id === null;
    }
}
