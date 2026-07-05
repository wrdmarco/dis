<?php

namespace App\Http\Requests\Incidents;

use App\Services\PilotIncidentReportFormService;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePilotIncidentReportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return app(PilotIncidentReportFormService::class)->validationRules($this->user());
    }
}
