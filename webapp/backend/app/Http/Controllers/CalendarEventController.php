<?php

namespace App\Http\Controllers;

use App\Http\Requests\Calendar\DeleteCalendarEventRequest;
use App\Http\Requests\Calendar\ListCalendarEventsRequest;
use App\Http\Requests\Calendar\ListCalendarTeamOptionsRequest;
use App\Http\Requests\Calendar\StoreCalendarEventRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ApiDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class CalendarEventController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(ListCalendarEventsRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $from = isset($data['from']) ? Carbon::parse((string) $data['from']) : now()->subDay();
        $until = isset($data['until']) ? Carbon::parse((string) $data['until']) : now()->addMonths(3);
        $limit = (int) ($data['limit'] ?? 100);
        $teamIds = $this->userTeamIds($user);

        $events = CalendarEvent::query()
            ->with(['team', 'creator'])
            ->where(function ($query) use ($from): void {
                $query->whereNull('ends_at')
                    ->where('starts_at', '>=', $from)
                    ->orWhere('ends_at', '>=', $from);
            })
            ->where('starts_at', '<=', $until)
            ->where(function ($query) use ($teamIds): void {
                $query->whereNull('team_id');
                if ($teamIds !== []) {
                    $query->orWhereIn('team_id', $teamIds);
                }
            })
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
        $data = $request->validated();

        if (! $this->mayUseTeam($request->user(), $data['team_id'] ?? null)) {
            abort(403);
        }

        $event = CalendarEvent::query()->create($data + [
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $this->auditService->record('calendar_events.created', $event, $request->user(), [], null, $request);

        return ApiResponse::success($this->payload($event->load(['team', 'creator'])), 201);
    }

    public function destroy(DeleteCalendarEventRequest $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $calendarEvent->delete();
        $this->auditService->record('calendar_events.deleted', $calendarEvent, $request->user(), [], null, $request);

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

    private function mayUseTeam(?User $user, ?string $teamId): bool
    {
        if ($teamId === null || $user === null) {
            return true;
        }

        return $user->hasPermission('calendar.manage') || $user->hasPermission('teams.manage');
    }
}
