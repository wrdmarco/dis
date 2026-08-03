<?php

namespace App\Http\Requests\Deployments;

use Illuminate\Foundation\Http\FormRequest;

final class ListDeploymentPilotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (
            $user->hasPermission('deployments.dispatch.view')
            || $user->hasPermission('deployments.dispatch.manage')
        );
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
