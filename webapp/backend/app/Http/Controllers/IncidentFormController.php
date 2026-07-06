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

    public function show(Request $request): JsonResponse
    {
        $target = (string) $request->query('target', $request->is('api/admin/*') ? 'web' : 'operator');

        return ApiResponse::success([
            'fields' => $this->formService->fields(target: $target),
            'layout' => $target === 'operator' ? [] : $this->formService->layout(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array'],
            'layout' => ['nullable', 'array'],
        ]);

        $fields = $this->formService->validateFields($data['fields']);
        $layout = $this->formService->validateLayout($data['layout'] ?? []);

        SystemSetting::query()->updateOrCreate(
            ['key' => IncidentFormService::SETTING_KEY],
            ['value' => $fields, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
        );
        SystemSetting::query()->updateOrCreate(
            ['key' => IncidentFormService::LAYOUT_SETTING_KEY],
            ['value' => $layout, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
        );

        return $this->show($request);
    }
}
