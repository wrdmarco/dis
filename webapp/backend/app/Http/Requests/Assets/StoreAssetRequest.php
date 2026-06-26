<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('assets.manage') === true;
    }

    public function rules(): array
    {
        return [
            'asset_tag' => ['nullable', 'string', 'max:80', 'unique:assets,asset_tag'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:drone,battery,sensor,vehicle,support_equipment'],
            'drone_type_id' => ['nullable', 'required_if:type,drone', 'ulid', 'exists:drone_types,id'],
            'status' => ['required', 'in:ready,assigned,maintenance,unavailable,retired'],
            'serial_number' => ['nullable', 'string', 'max:160', 'unique:assets,serial_number'],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
