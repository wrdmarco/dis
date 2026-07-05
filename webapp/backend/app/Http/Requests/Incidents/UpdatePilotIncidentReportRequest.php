<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\PilotIncidentReportFormService;

final class UpdatePilotIncidentReportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return app(PilotIncidentReportFormService::class)->validationRules();
    }
}
