<?php

namespace App\Http\Controllers;

use App\Http\Requests\Calendar\DeleteCalendarEventRequest;
use App\Http\Requests\Calendar\ListCalendarEventsRequest;
use App\Http\Requests\Calendar\ListCalendarTeamOptionsRequest;
use App\Http\Requests\Calendar\StoreCalendarEventRequest;
use App\Http\Requests\Calendar\UpdateCalendarEventRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\CalendarEventService;
use App\Support\ApiDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class CalendarEventController extends Controller
{
    public function __construct(private readonly CalendarEventService $service) {}

    public function index(ListCalendarEventsRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $from = isset($data['from']) ? Carbon::parse((string) $data['from']) : now()->subDay();
        $until = isset($data['until']) ? Carbon::parse((string) $data['until']) : now()->addMonths(3);
        $limit = (int) ($data['limit'] ?? 100);
        $teamIds = $this->userTeamIds($user);

        $query = CalendarEvent::query()
            ->with(['team', 'creator'])
            ->where(function ($query) use ($from): void {
                $query->whereNull('ends_at')
                    ->where('starts_at', '>=', $from)
                    ->orWhere('ends_at', '>=', $from);
            })
            ->where('starts_at', '<=', $until);

        if ($user?->hasPermission('calendar.manage') !== true) {
            $query->where(function ($query) use ($teamIds): void {
                $query->whereNull('team_id');
                if ($teamIds !== []) {
                    $query->orWhereIn('team_id', $teamIds);
                }
            });
        }

        $events = $query
            ->orderBy('starts_at')
            ->limit($limit)
            ->get()
            ->map(fn (CalendarEvent $event): array => $this->payload($event))
            ->values();

        return ApiResponse::success($events);
    }

    public function teamOptions(ListCalendarTeamOptionsRequest $request): JsonResponse
    {
        $teams = Team::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'is_operational'])
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'code' => $team->code,
                'name' => $team->name,
                'type' => $team->type,
                'is_operational' => (bool) $team->is_operational,
            ])
            ->values();

        return ApiResponse::success($teams);
    }

    public function store(StoreCalendarEventRequest $request): JsonResponse
    {
        $event = $this->service->create(
            $request->validated(),
            $request->user(),
            $request,
        );

        return ApiResponse::success($this->payload($event), 201);
    }

    public function update(
        UpdateCalendarEventRequest $request,
        CalendarEvent $calendarEvent,
    ): JsonResponse {
        $event = $this->service->update(
            $calendarEvent,
            $request->validated(),
            $request->user(),
            $request,
        );

        return ApiResponse::success($this->payload($event));
    }

    public function destroy(DeleteCalendarEventRequest $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $this->service->delete($calendarEvent, $request->user(), $request);

        return ApiResponse::success(null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'type' => $event->type,
            'starts_at' => ApiDateTime::dateTime($event->starts_at),
            'ends_at' => ApiDateTime::dateTime($event->ends_at),
            'location_label' => $event->location_label,
            'description' => $event->description,
            'team_id' => $event->team_id,
            'team' => $event->team === null ? null : [
                'id' => $event->team->id,
                'code' => $event->team->code,
                'name' => $event->team->name,
                'type' => $event->team->type,
            ],
            'created_by_name' => $event->creator?->name,
            'created_at' => ApiDateTime::dateTime($event->created_at),
        ];
    }

    /**
     * @return list<string>
     */
    private function userTeamIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $user->teams()->pluck('teams.id')->values()->all();
    }
}
