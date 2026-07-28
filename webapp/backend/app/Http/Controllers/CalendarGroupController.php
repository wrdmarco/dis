<?php

namespace App\Http\Controllers;

use App\Exceptions\CalendarEventConflictException;
use App\Http\Requests\Calendar\DeleteCalendarGroupRequest;
use App\Http\Requests\Calendar\ListCalendarGroupMemberOptionsRequest;
use App\Http\Requests\Calendar\ListCalendarGroupsRequest;
use App\Http\Requests\Calendar\ShowCalendarGroupRequest;
use App\Http\Requests\Calendar\StoreCalendarGroupRequest;
use App\Http\Requests\Calendar\UpdateCalendarGroupRequest;
use App\Http\Resources\CalendarGroupResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarGroup;
use App\Services\CalendarGroupService;
use Illuminate\Http\JsonResponse;

final class CalendarGroupController extends Controller
{
    public function __construct(private readonly CalendarGroupService $service) {}

    public function index(ListCalendarGroupsRequest $request): JsonResponse
    {
        return ApiResponse::success(
            CalendarGroupResource::collection(
                $this->service->all($request->user()),
            )->resolve($request),
        );
    }

    public function memberOptions(ListCalendarGroupMemberOptionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $options = $this->service->memberOptions(
            $request->user(),
            isset($data['search']) ? (string) $data['search'] : null,
        );

        return ApiResponse::success([
            'users' => $options['users']
                ->map(static fn ($user): array => [
                    'id' => (string) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ])
                ->values(),
            'teams' => $options['teams']
                ->map(static fn ($team): array => [
                    'id' => (string) $team->id,
                    'code' => (string) $team->code,
                    'name' => (string) $team->name,
                ])
                ->values(),
        ]);
    }

    public function store(StoreCalendarGroupRequest $request): JsonResponse
    {
        $group = $this->service->create(
            $request->validated(),
            $request->user(),
            $request,
        );

        return ApiResponse::success(
            (new CalendarGroupResource($group))->resolve($request),
            201,
        );
    }

    public function show(
        ShowCalendarGroupRequest $request,
        CalendarGroup $calendarGroup,
    ): JsonResponse {
        return ApiResponse::success(
            (new CalendarGroupResource(
                $this->service->show($calendarGroup, $request->user()),
            ))->resolve($request),
        );
    }

    public function update(
        UpdateCalendarGroupRequest $request,
        CalendarGroup $calendarGroup,
    ): JsonResponse {
        try {
            $group = $this->service->update(
                $calendarGroup,
                $request->validated(),
                $request->user(),
                $request,
            );
        } catch (CalendarEventConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success(
            (new CalendarGroupResource($group))->resolve($request),
        );
    }

    public function destroy(
        DeleteCalendarGroupRequest $request,
        CalendarGroup $calendarGroup,
    ): JsonResponse {
        try {
            $this->service->delete($calendarGroup, $request->user(), $request);
        } catch (CalendarEventConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success(null);
    }

    private function conflict(CalendarEventConflictException $exception): JsonResponse
    {
        return ApiResponse::error(
            $exception->errorCode,
            $exception->getMessage(),
            409,
            $exception->details,
        );
    }
}
