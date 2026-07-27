<?php

namespace App\Http\Requests\DeploymentRequests;

use Illuminate\Foundation\Http\FormRequest;

final class DecideDeploymentRequestPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('deployments.manage') === true;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'client_mutation_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'priority' => ['required', 'string', 'in:low,medium,high,urgent'],
            'selected_deployment_profile_id' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]{1,60}$/'],
            'deployment_adjustments' => ['sometimes', 'array:team_ids,resources,notes,recommended_recipient_count,recommended_dispatch_mode,required_certification_type_ids'],
            'deployment_adjustments.team_ids' => ['sometimes', 'array', 'max:50'],
            'deployment_adjustments.team_ids.*' => ['ulid', 'exists:teams,id'],
            'deployment_adjustments.resources' => ['sometimes', 'array', 'max:50'],
            'deployment_adjustments.resources.*' => ['string', 'max:160'],
            'deployment_adjustments.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'deployment_adjustments.recommended_recipient_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
            'deployment_adjustments.recommended_dispatch_mode' => ['sometimes', 'nullable', 'string', 'in:preannouncement,direct_dispatch'],
            'deployment_adjustments.required_certification_type_ids' => ['sometimes', 'array', 'max:50'],
            'deployment_adjustments.required_certification_type_ids.*' => ['ulid', 'exists:certifications,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
