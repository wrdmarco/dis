<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incidents.manage') === true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['required', 'in:low,normal,high,critical'],
            'status' => ['nullable', 'in:draft,active,dispatching,in_progress,resolved,cancelled'],
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
