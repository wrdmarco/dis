<?php

namespace App\Http\Requests\Incidents;

use App\Models\Incident;
use App\Services\PilotIncidentReportFormService;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePilotIncidentReportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $incident = $this->route('incident');

        return app(PilotIncidentReportFormService::class)->validationRules(
            $this->user(),
            $incident instanceof Incident ? $incident : null,
        );
    }
}
