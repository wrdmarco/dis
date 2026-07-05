<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AvailabilityOverride;
use App\Models\AvailabilityWeekPattern;
use App\Models\User;
use App\Services\AvailabilityScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AvailabilityScheduleController extends Controller
{
    public function __construct(private readonly AvailabilityScheduleService $service) {}

    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::success($this->schedulePayload($request->user()));
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success($this->schedulePayload($user));
    }

    public function updateMine(Request $request): JsonResponse
    {
        return $this->updatePattern($request, $request->user());
    }

    public function updateForUser(Request $request, User $user): JsonResponse
    {
        return $this->updatePattern($request, $user);
    }

    public function storeMineOverride(Request $request): JsonResponse
    {
        return $this->storeOverride($request, $request->user());
    }

    public function storeUserOverride(Request $request, User $user): JsonResponse
    {
        return $this->storeOverride($request, $user);
    }

    public function deleteOverride(Request $request, AvailabilityOverride $override): JsonResponse
    {
        if (
            $override->user_id !== $request->user()?->id
            && $request->user()?->hasPermission('status.override') !== true
        ) {
            abort(403);
        }

        $this->service->deleteOverride($override->load('user'), $request->user());

        return ApiResponse::success(null);
    }

    private function updatePattern(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'patterns' => ['required', 'array', 'min:7', 'max:21'],
            'patterns.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'patterns.*.day_part' => ['nullable', 'string', 'in:all_day,morning,afternoon,evening'],
            'patterns.*.is_available' => ['required', 'boolean'],
            'patterns.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $patterns = collect($data['patterns'])
            ->map(fn (array $pattern): array => $pattern + ['day_part' => 'all_day'])
            ->unique(fn (array $pattern): string => $pattern['day_of_week'].'-'.$pattern['day_part'])
            ->values();
        if ($patterns->count() !== count($data['patterns'])) {
            abort(422, 'Iedere combinatie van weekdag en dagdeel mag precies een keer worden opgegeven.');
        }

        $this->service->replaceWeekPattern($user, $patterns->all(), $request->user());

        return ApiResponse::success($this->schedulePayload($user));
    }

    private function storeOverride(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'day_part' => ['nullable', 'string', 'in:all_day,morning,afternoon,evening'],
            'is_available' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->service->createOverride($user, $data, $request->user());

        return ApiResponse::success($this->schedulePayload($user), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(User $user): array
    {
        $patterns = AvailabilityWeekPattern::query()
            ->where('user_id', $user->id)
            ->orderBy('day_of_week')
            ->orderBy('day_part')
            ->get()
            ->keyBy(fn (AvailabilityWeekPattern $pattern): string => $pattern->day_of_week.'-'.($pattern->day_part ?? 'all_day'));

        $weekPattern = collect(range(1, 7))
            ->map(function (int $day) use ($patterns): array {
                $pattern = $patterns->get($day.'-all_day');

                return [
                    'day_of_week' => $day,
                    'day_part' => 'all_day',
                    'is_available' => $pattern?->is_available ?? true,
                    'note' => $pattern?->note,
                    'source' => $pattern === null ? 'default' : 'pattern',
                ];
            })
            ->values();
        $weekDayParts = collect(range(1, 7))
            ->flatMap(fn (int $day): array => collect(['morning', 'afternoon', 'evening'])
                ->map(function (string $dayPart) use ($patterns, $day): array {
                    $pattern = $patterns->get($day.'-'.$dayPart)
                        ?? $patterns->get($day.'-all_day');

                    return [
                        'day_of_week' => $day,
                        'day_part' => $dayPart,
                        'is_available' => $pattern?->is_available ?? true,
                        'note' => $pattern?->note,
                        'source' => $pattern === null ? 'default' : 'pattern',
                    ];
                })
                ->all())
            ->values();

        $overrides = AvailabilityOverride::query()
            ->where('user_id', $user->id)
            ->whereDate('ends_at', '>=', today()->subDays(7))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (AvailabilityOverride $override): array => [
                'id' => $override->id,
                'starts_at' => $override->starts_at?->toDateString(),
                'ends_at' => $override->ends_at?->toDateString(),
                'day_part' => $override->day_part ?? 'all_day',
                'is_available' => (bool) $override->is_available,
                'note' => $override->note,
            ])
            ->values();

        return [
            'user_id' => $user->id,
            'week_pattern' => $weekPattern,
            'week_day_parts' => $weekDayParts,
            'overrides' => $overrides,
            'today' => $this->service->availabilityFor($user),
        ];
    }
}
