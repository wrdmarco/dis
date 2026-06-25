<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Repositories\IncidentRepository;
use App\Services\IncidentService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IncidentController extends Controller
{
    public function __construct(private readonly IncidentRepository $incidents, private readonly IncidentService $service) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->has('per_page')) {
            $incidents = $this->incidents
                ->search($request->only(['status', 'priority']), 100)
                ->getCollection()
                ->map(fn (Incident $incident): array => MobileApiPayload::incident($incident))
                ->values();

            return ApiResponse::success($incidents);
        }

        return ApiResponse::paginated(
            $this->incidents->search($request->only(['status', 'priority']), (int) $request->integer('per_page', 25)),
            fn (Incident $incident): array => MobileApiPayload::incident($incident),
        );
    }

    public function store(StoreIncidentRequest $request): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::incident($this->service->create($request->validated(), $request->user())), 201);
    }

    public function show(Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::incident($incident->load(['coordinator', 'team'])));
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::incident($this->service->update($incident, $request->validated(), $request->user())));
    }

    public function close(Request $request, Incident $incident): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success(MobileApiPayload::incident($this->service->close($incident, $request->user(), $request->input('reason'))));
    }

    public function cancel(Request $request, Incident $incident): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success(MobileApiPayload::incident($this->service->cancel($incident, $request->user(), $request->input('reason'))));
    }

    public function timeline(Incident $incident): JsonResponse
    {
        return ApiResponse::success($incident->statusHistory()->latest('created_at')->get());
    }
}
