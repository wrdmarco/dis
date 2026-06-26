<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\DroneType;
use App\Services\AuditService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DroneTypeController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        $query = DroneType::query()
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderBy('manufacturer')
            ->orderBy('model');

        return ApiResponse::success($query->get()->map(fn (DroneType $type): array => MobileApiPayload::droneType($type))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $droneType = DroneType::query()->create($request->validate([
            'manufacturer' => ['required', 'string', 'max:120'],
            'model' => ['required', 'string', 'max:160', 'unique:drone_types,model'],
            'has_thermal' => ['required', 'boolean'],
            'has_spotlight' => ['required', 'boolean'],
            'has_speaker' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]));

        $this->auditService->record('drone_types.created', $droneType, $request->user());

        return ApiResponse::success(MobileApiPayload::droneType($droneType), 201);
    }

    public function update(Request $request, DroneType $droneType): JsonResponse
    {
        $droneType->update($request->validate([
            'manufacturer' => ['sometimes', 'string', 'max:120'],
            'model' => ['sometimes', 'string', 'max:160', Rule::unique('drone_types', 'model')->ignore($droneType->id)],
            'has_thermal' => ['sometimes', 'boolean'],
            'has_spotlight' => ['sometimes', 'boolean'],
            'has_speaker' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]));

        $this->auditService->record('drone_types.updated', $droneType, $request->user());

        return ApiResponse::success(MobileApiPayload::droneType($droneType->refresh()));
    }

    public function destroy(Request $request, DroneType $droneType): JsonResponse
    {
        $droneType->delete();
        $this->auditService->record('drone_types.deleted', $droneType, $request->user());

        return ApiResponse::success(null);
    }
}
