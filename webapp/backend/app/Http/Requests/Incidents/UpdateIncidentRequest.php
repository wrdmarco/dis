<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incidents.manage') === true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'reporter_name' => ['nullable', 'string', 'max:180'],
            'reporter_phone' => ['nullable', 'string', 'max:40'],
            'requesting_organization' => ['nullable', 'string', 'max:180'],
            'requesting_unit' => ['nullable', 'string', 'max:180'],
            'on_scene_contact_name' => ['nullable', 'string', 'max:180'],
            'on_scene_contact_phone' => ['nullable', 'string', 'max:40'],
            'on_scene_contact_role' => ['nullable', 'string', 'max:120'],
            'required_resources' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', 'in:low,normal,high,critical'],
            'status' => ['sometimes', 'in:draft,active,dispatching,in_progress,resolved,cancelled'],
            'status_reason' => ['nullable', 'string', 'max:1000'],
            'direct_dispatch' => ['sometimes', 'boolean'],
            'dispatch_recipient_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'location_label' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'coordinator_id' => ['nullable', 'ulid', 'exists:users,id'],
            'team_id' => ['nullable', 'ulid', 'exists:teams,id'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['ulid', 'exists:teams,id'],
        ];
    }
}
