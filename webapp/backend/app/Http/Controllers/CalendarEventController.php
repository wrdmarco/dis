<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CalendarEventController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->date('from') ?? now()->subDay();
        $until = $request->date('until') ?? now()->addMonths(3);
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
            ->limit((int) $request->integer('limit', 100))
            ->get()
            ->map(fn (CalendarEvent $event): array => $this->payload($event))
            ->values();

        return ApiResponse::success($events);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()?->hasPermission('settings.manage') !== true) {
            abort(403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'type' => ['required', 'string', Rule::in(['training', 'open_day', 'meeting', 'exercise', 'other'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location_label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'team_id' => ['nullable', 'ulid', 'exists:teams,id'],
        ]);

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

    public function destroy(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        if ($request->user()?->hasPermission('settings.manage') !== true) {
            abort(403);
        }

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
            'starts_at' => $event->starts_at?->toJSON(),
            'ends_at' => $event->ends_at?->toJSON(),
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
            'created_at' => $event->created_at?->toJSON(),
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

        return $user->hasPermission('settings.manage') || $user->hasPermission('teams.manage');
    }
}
