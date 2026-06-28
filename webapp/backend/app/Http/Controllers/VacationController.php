<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\UserVacation;
use App\Services\VacationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VacationController extends Controller
{
    public function __construct(private readonly VacationService $service) {}

    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::success(
            UserVacation::query()
                ->where('user_id', $request->user()?->id)
                ->latest('starts_at')
                ->get()
                ->map(fn (UserVacation $vacation): array => $this->payload($vacation))
                ->values(),
        );
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            UserVacation::query()
                ->with('user')
                ->open()
                ->orderBy('starts_at')
                ->get()
                ->map(fn (UserVacation $vacation): array => $this->payload($vacation))
                ->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return ApiResponse::success($this->payload($this->service->create($request->user(), $data, $request->user())), 201);
    }

    public function cancel(Request $request, UserVacation $vacation): JsonResponse
    {
        if ($vacation->user_id !== $request->user()?->id && $request->user()?->hasPermission('status.override') !== true) {
            abort(403);
        }

        return ApiResponse::success($this->payload($this->service->cancel($vacation->load('user'), $request->user())));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(UserVacation $vacation): array
    {
        return [
            'id' => $vacation->id,
            'user_id' => $vacation->user_id,
            'starts_at' => $vacation->starts_at?->toDateString(),
            'ends_at' => $vacation->ends_at?->toDateString(),
            'status' => $vacation->status,
            'note' => $vacation->note,
            'user' => $vacation->relationLoaded('user') && $vacation->user !== null ? [
                'id' => $vacation->user->id,
                'name' => $vacation->user->name,
                'email' => $vacation->user->email,
            ] : null,
        ];
    }
}
