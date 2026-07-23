<?php

namespace App\Http\Requests\Incidents;

use App\Models\Incident;
use App\Models\Role;
use App\Services\IncidentFormService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incidents.manage') === true;
    }

    public function rules(): array
    {
        $incidentForm = app(IncidentFormService::class);

        return $incidentForm->fixedInputValidationRules(partial: true, enforceConfigurableRequired: false) + [
            'requesting_organization' => ['nullable', 'string', 'max:180'],
            'requesting_unit' => ['nullable', 'string', 'max:180'],
            'on_scene_contact_name' => ['nullable', 'string', 'max:180'],
            'on_scene_contact_phone' => ['nullable', 'string', 'max:40'],
            'on_scene_contact_role' => ['nullable', 'string', 'max:120'],
            'required_resources' => ['nullable', 'string', 'max:5000'],
            'status_reason' => ['nullable', 'string', 'max:1000'],
            'direct_dispatch' => ['sometimes', 'boolean'],
            'manual_status_override' => ['sometimes', 'boolean'],
            'dispatch_recipient_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'coordinator_id' => ['nullable', 'ulid', 'exists:users,id'],
            'team_id' => ['nullable', 'ulid', 'exists:teams,id'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['ulid', 'exists:teams,id'],
        ] + $incidentForm->validationRules(partial: true);
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->boolean('manual_status_override')) {
                return;
            }

            if ($this->user()?->hasRole(Role::SYSTEM_ADMINISTRATOR) !== true) {
                $validator->errors()->add(
                    'manual_status_override',
                    'Alleen een systeembeheerder mag de incidentstatus handmatig corrigeren.',
                );

                return;
            }

            $incident = $this->route('incident');
            if (! $incident instanceof Incident || ! $this->exists('status')) {
                $validator->errors()->add(
                    'status',
                    'Kies een status voor de handmatige correctie.',
                );

                return;
            }

            if (
                (string) $this->input('status') !== (string) $incident->status
                && trim((string) $this->input('status_reason')) === ''
            ) {
                $validator->errors()->add(
                    'status_reason',
                    'Leg de reden van de handmatige statuscorrectie vast.',
                );
            }
        }];
    }
}
