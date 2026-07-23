<?php

namespace App\Http\Requests\Incidents;

use App\Services\IncidentFormService;
use Illuminate\Foundation\Http\FormRequest;

final class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incidents.manage') === true;
    }

    public function rules(): array
    {
        $incidentForm = app(IncidentFormService::class);
        $rules = $incidentForm->fixedInputValidationRules();
        $rules['status'] = ['sometimes', 'string', 'in:draft'];

        return $rules + [
            'requesting_organization' => ['nullable', 'string', 'max:180'],
            'requesting_unit' => ['nullable', 'string', 'max:180'],
            'on_scene_contact_name' => ['nullable', 'string', 'max:180'],
            'on_scene_contact_phone' => ['nullable', 'string', 'max:40'],
            'on_scene_contact_role' => ['nullable', 'string', 'max:120'],
            'required_resources' => ['nullable', 'string', 'max:5000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'coordinator_id' => ['nullable', 'ulid', 'exists:users,id'],
            'team_id' => ['nullable', 'ulid', 'exists:teams,id'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['ulid', 'exists:teams,id'],
        ] + $incidentForm->validationRules();
    }
}
