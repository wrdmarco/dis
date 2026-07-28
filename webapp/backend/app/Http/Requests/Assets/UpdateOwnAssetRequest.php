<?php

namespace App\Http\Requests\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOwnAssetRequest extends FormRequest
{
    private ?AssetAssignment $activeAssignment = null;

    public function authorize(): bool
    {
        $asset = $this->route('asset');
        $userId = $this->user()?->getKey();

        if (! $asset instanceof Asset || $userId === null) {
            return false;
        }

        $this->activeAssignment = $asset->activeAssignment()->first();

        return $this->activeAssignment?->user_id === $userId;
    }

    public function rules(): array
    {
        $asset = $this->route('asset');
        $assetId = $asset instanceof Asset ? (string) $asset->getKey() : '';
        $canEditIdentity = $this->canEditIdentity();

        return [
            'name' => [Rule::prohibitedIf(! $canEditIdentity), 'sometimes', 'string', 'max:160'],
            'type' => [Rule::prohibitedIf(! $canEditIdentity), 'sometimes', 'in:drone,battery,sensor,vehicle,support_equipment'],
            'drone_type_id' => [
                Rule::prohibitedIf(! $canEditIdentity),
                Rule::requiredIf(fn (): bool => $this->requiresDroneType($asset)),
                'nullable',
                'ulid',
                'exists:drone_types,id',
            ],
            'has_spotlight' => ['sometimes', 'boolean'],
            'has_speaker' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:ready,maintenance,unavailable'],
            'serial_number' => [
                Rule::prohibitedIf(! $canEditIdentity),
                'sometimes',
                'nullable',
                'string',
                'max:160',
                Rule::unique('assets', 'serial_number')->ignore($assetId),
            ],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function canEditIdentity(): bool
    {
        $userId = $this->user()?->getKey();

        // AssetService::createForUser records self-registration as an assignment
        // where the assignee also assigned the asset. Reassignment fails closed.
        return $userId !== null && $this->activeAssignment?->assigned_by === $userId;
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
