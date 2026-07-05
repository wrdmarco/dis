<?php

namespace App\Http\Controllers;

use App\Http\Requests\Status\UpdateStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AvailabilityStatus;
use App\Models\User;
use App\Services\AvailabilityScheduleService;
use App\Services\StatusService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StatusController extends Controller
{
    public function __construct(
        private readonly StatusService $service,
        private readonly AvailabilityScheduleService $availabilityScheduleService,
    ) {}

    public function me(Request $request): JsonResponse
    {
        $status = $request->user()
            ?->statuses()
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return ApiResponse::success($status === null ? null : MobileApiPayload::status(
            $status,
            $this->availabilityScheduleService->nextAvailabilityChange($request->user()),
            $this->availabilityScheduleService->nextAvailableAt($request->user()),
        ));
    }

    public function updateMe(UpdateStatusRequest $request): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::status(
            $this->service->setStatus($request->user(), $request->validated('status'), $request->user(), $request->validated('reason')),
            $this->availabilityScheduleService->nextAvailabilityChange($request->user()),
            $this->availabilityScheduleService->nextAvailableAt($request->user()),
        ));
    }

    public function users(Request $request): JsonResponse
    {
        return ApiResponse::paginated(
            AvailabilityStatus::query()
                ->latestPerUser()
                ->with('user')
                ->latest('effective_at')
                ->paginate((int) $request->integer('per_page', 25)),
            fn (AvailabilityStatus $status): array => MobileApiPayload::status(
                $status,
                $status->user === null ? null : $this->availabilityScheduleService->nextAvailabilityChange($status->user),
                $status->user === null ? null : $this->availabilityScheduleService->nextAvailableAt($status->user),
            ),
        );
    }

    public function override(UpdateStatusRequest $request, User $user): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::status(
            $this->service->setStatus($user, $request->validated('status'), $request->user(), $request->validated('reason')),
            $this->availabilityScheduleService->nextAvailabilityChange($user),
            $this->availabilityScheduleService->nextAvailableAt($user),
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
