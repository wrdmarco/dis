<?php

namespace App\Http\Requests\Deployments;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDeploymentPilotStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null
            && ($actor->hasPermission('deployments.dispatch.manage')
                || $actor->hasPermission('status.override'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:en_route,on_scene'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
