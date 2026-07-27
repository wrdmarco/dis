<?php

namespace App\Http\Requests\Deployments;

use App\Models\Deployment;
use App\Services\PilotDeploymentReportFormService;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePilotDeploymentReportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $deployment = $this->route('deployment');

        return app(PilotDeploymentReportFormService::class)->validationRules(
            $this->user(),
            $deployment instanceof Deployment ? $deployment : null,
        );
    }
}
