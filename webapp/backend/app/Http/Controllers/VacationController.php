<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AvailabilityOverride;
use App\Models\User;
use App\Services\AvailabilityScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class VacationController extends Controller
{
    private const DAY_PART_ALL_DAY = 'all_day';

    public function __construct(private readonly AvailabilityScheduleService $service) {}

    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::success(
            AvailabilityOverride::query()
                ->where('user_id', $request->user()?->id)
                ->where('day_part', self::DAY_PART_ALL_DAY)
                ->whereDate('ends_at', '>=', today())
                ->orderBy('starts_at')
                ->get()
                ->map(fn (AvailabilityOverride $vacation): array => $this->payload($vacation))
                ->values(),
        );
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            AvailabilityOverride::query()
                ->with('user')
                ->where('day_part', self::DAY_PART_ALL_DAY)
                ->whereDate('ends_at', '>=', today())
                ->orderBy('starts_at')
                ->get()
                ->map(fn (AvailabilityOverride $vacation): array => $this->payload($vacation))
                ->values(),
        );
    }

    public function userVacations(User $user): JsonResponse
    {
        return ApiResponse::success(
            AvailabilityOverride::query()
                ->with('user')
                ->where('user_id', $user->id)
                ->where('day_part', self::DAY_PART_ALL_DAY)
                ->whereDate('ends_at', '>=', today())
                ->orderBy('starts_at')
                ->get()
                ->map(fn (AvailabilityOverride $vacation): array => $this->payload($vacation))
                ->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_available' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['is_available'] ??= false;

        return ApiResponse::success(
            $this->payload($this->service->createOverride($request->user(), $data, $request->user())),
            201,
        );
    }

    public function storeForUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_available' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['is_available'] ??= false;

        return ApiResponse::success(
            $this->payload($this->service->createOverride($user, $data, $request->user())->load('user')),
            201,
        );
    }

    public function update(Request $request, AvailabilityOverride $vacation): JsonResponse
    {
        $this->assertVacationAliasAccess($request, $vacation);

        $data = $request->validate([
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'required', 'date'],
            'is_available' => ['required', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $startsAt = CarbonImmutable::parse($data['starts_at'] ?? $vacation->starts_at)->startOfDay();
        $endsAt = CarbonImmutable::parse($data['ends_at'] ?? $vacation->ends_at)->startOfDay();
        if ($endsAt->lessThan($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => ['Einddatum mag niet voor de begindatum liggen.'],
            ]);
        }

        return ApiResponse::success($this->payload(
            $this->service->updateOverride($vacation->load('user'), $data, $request->user()),
        ));
    }

    public function cancel(Request $request, AvailabilityOverride $vacation): JsonResponse
    {
        $this->assertVacationAliasAccess($request, $vacation);
        $vacation->load('user');
        $payload = $this->payload($vacation);
        $this->service->deleteOverride($vacation, $request->user());

        return ApiResponse::success($payload);
    }

    private function assertVacationAliasAccess(Request $request, AvailabilityOverride $vacation): void
    {
        if ($vacation->day_part !== self::DAY_PART_ALL_DAY) {
            abort(404);
        }

        if (
            $vacation->user_id !== $request->user()?->id
            && $request->user()?->hasPermission('vacations.manage') !== true
        ) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AvailabilityOverride $vacation): array
    {
        $today = CarbonImmutable::today();
        $isActive = $vacation->starts_at !== null
            && $vacation->ends_at !== null
            && $vacation->starts_at->lessThanOrEqualTo($today)
            && $vacation->ends_at->greaterThanOrEqualTo($today);

        return [
            'id' => $vacation->id,
            'user_id' => $vacation->user_id,
            'starts_at' => $vacation->starts_at?->toDateString(),
            'ends_at' => $vacation->ends_at?->toDateString(),
            'is_available' => (bool) $vacation->is_available,
            'status' => $isActive ? 'active' : 'scheduled',
            'note' => $vacation->note,
            'user' => $vacation->relationLoaded('user') && $vacation->user !== null ? [
                'id' => $vacation->user->id,
                'name' => $vacation->user->name,
                'email' => $vacation->user->email,
            ] : null,
        ];
    }
}
