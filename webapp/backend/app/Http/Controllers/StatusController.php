<?php

namespace App\Http\Controllers;

use App\Http\Requests\Status\UpdateStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AvailabilityStatus;
use App\Models\User;
use App\Services\StatusService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StatusController extends Controller
{
    public function __construct(private readonly StatusService $service) {}

    public function me(Request $request): JsonResponse
    {
        $status = $request->user()?->statuses()->latest('effective_at')->first();

        return ApiResponse::success($status === null ? null : MobileApiPayload::status($status));
    }

    public function updateMe(UpdateStatusRequest $request): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::status(
            $this->service->setStatus($request->user(), $request->validated('status'), $request->user(), $request->validated('reason')),
        ));
    }

    public function users(Request $request): JsonResponse
    {
        return ApiResponse::paginated(
            AvailabilityStatus::query()->with('user')->latest('effective_at')->paginate((int) $request->integer('per_page', 25)),
            fn (AvailabilityStatus $status): array => MobileApiPayload::status($status),
        );
    }

    public function override(UpdateStatusRequest $request, User $user): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::status(
            $this->service->setStatus($user, $request->validated('status'), $request->user(), $request->validated('reason')),
        ));
    }

    public function history(Request $request): JsonResponse
    {
        return ApiResponse::paginated(
            AvailabilityStatus::query()->latest('effective_at')->paginate((int) $request->integer('per_page', 25)),
            fn (AvailabilityStatus $status): array => MobileApiPayload::status($status),
        );
    }
}
