<?php

namespace App\Http\Controllers;

use App\Exceptions\CalendarEventConflictException;
use App\Http\Requests\Calendar\DeleteCalendarEventRequest;
use App\Http\Requests\Calendar\ListCalendarEventsRequest;
use App\Http\Requests\Calendar\ListCalendarGroupOptionsRequest;
use App\Http\Requests\Calendar\ListCalendarTeamOptionsRequest;
use App\Http\Requests\Calendar\StoreCalendarEventRequest;
use App\Http\Requests\Calendar\UpdateCalendarEventRequest;
use App\Http\Resources\CalendarEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Team;
use App\Services\CalendarEventService;
use App\Services\CalendarGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class CalendarEventController extends Controller
{
    public function __construct(
        private readonly CalendarEventService $service,
        private readonly CalendarGroupService $groups,
    ) {}

    public function index(ListCalendarEventsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $from = isset($data['from']) ? Carbon::parse((string) $data['from']) : now()->subDay();
        $until = isset($data['until']) ? Carbon::parse((string) $data['until']) : now()->addMonths(3);
        $events = $this->service->visibleBetween(
            $request->user(),
            $from,
            $until,
            (int) ($data['limit'] ?? 100),
        );

        return ApiResponse::success(
            CalendarEventResource::collection($events)->resolve($request),
        );
    }

    public function teamOptions(ListCalendarTeamOptionsRequest $request): JsonResponse
    {
        $teams = Team::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'is_operational'])
            ->map(static fn (Team $team): array => [
                'id' => (string) $team->id,
                'code' => (string) $team->code,
                'name' => (string) $team->name,
                'type' => (string) $team->type,
                'is_operational' => (bool) $team->is_operational,
            ])
            ->values();

        return ApiResponse::success($teams);
    }

    public function groupOptions(ListCalendarGroupOptionsRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->groups->eventOptions($request->user())
                ->map(static fn ($group): array => [
                    'id' => (string) $group->id,
                    'name' => (string) $group->name,
                    'is_everyone' => (bool) $group->is_everyone,
                    'effective_member_count' => (int) $group->effective_member_count,
                ])
                ->values(),
        );
    }

    public function store(StoreCalendarEventRequest $request): JsonResponse
    {
        $event = $this->service->create(
            $request->validated(),
            $request->user(),
            $request,
        );

        return ApiResponse::success(
            (new CalendarEventResource($event))->resolve($request),
            201,
        );
    }

    public function update(
        UpdateCalendarEventRequest $request,
        CalendarEvent $calendarEvent,
    ): JsonResponse {
        try {
            $event = $this->service->update(
                $calendarEvent,
                $request->validated(),
                $request->user(),
                $request,
            );
        } catch (CalendarEventConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success(
            (new CalendarEventResource($event))->resolve($request),
        );
    }

    public function destroy(
        DeleteCalendarEventRequest $request,
        CalendarEvent $calendarEvent,
    ): JsonResponse {
        $this->service->delete($calendarEvent, $request->user(), $request);

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
