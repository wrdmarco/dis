<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Services\IncidentFormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IncidentFormController extends Controller
{
    public function __construct(
        private readonly IncidentFormService $formService,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(['fields' => $this->formService->fields()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array'],
        ]);

        $fields = $this->formService->validateFields($data['fields']);

        SystemSetting::query()->updateOrCreate(
            ['key' => IncidentFormService::SETTING_KEY],
            ['value' => $fields, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
        );

        return ApiResponse::success(['fields' => $this->formService->fields()]);
    }
}
