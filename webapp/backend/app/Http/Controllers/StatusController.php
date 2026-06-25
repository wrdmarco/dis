<?php

namespace App\Http\Controllers;

use App\Http\Requests\Status\UpdateStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AvailabilityStatus;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StatusController extends Controller
{
    public function __construct(private readonly StatusService $service) {}

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($request->user()?->statuses()->latest('effective_at')->first());
    }

    public function updateMe(UpdateStatusRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->setStatus($request->user(), $request->validated('status'), $request->user(), $request->validated('reason')));
    }

    public function users(Request $request): JsonResponse
    {
        return ApiResponse::paginated(AvailabilityStatus::query()->with('user')->latest('effective_at')->paginate((int) $request->integer('per_page', 25)));
    }

    public function override(UpdateStatusRequest $request, User $user): JsonResponse
    {
        return ApiResponse::success($this->service->setStatus($user, $request->validated('status'), $request->user(), $request->validated('reason')));
    }

    public function history(Request $request): JsonResponse
    {
        return ApiResponse::paginated(AvailabilityStatus::query()->latest('effective_at')->paginate((int) $request->integer('per_page', 25)));
    }
}

