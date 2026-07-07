<?php

namespace App\Http\Controllers;

use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\UserService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class UserController extends Controller
{
    public function __construct(private readonly UserRepository $users, private readonly UserService $service) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->users->search($request->only(['search', 'status', 'role', 'team']), (int) $request->integer('per_page', 25)));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->create($request->validated(), $request->user()), 201);
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::user($user->load([
            'roles.permissions',
            'teams',
            'certifications.certification',
            'assetAssignments' => fn ($query) => $query
                ->whereNull('released_at')
                ->with('asset.droneType')
                ->latest('assigned_at'),
            'fcmTokens' => fn ($query) => $query
                ->latest('last_seen_at'),
        ])));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        return ApiResponse::success($this->service->update($user, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, User $user): Response
    {
        $this->service->delete($user, $request->user());

        return response()->noContent();
    }

    public function assignRole(Request $request, User $user): JsonResponse
    {
        $request->validate(['role_id' => ['required', 'ulid', 'exists:roles,id']]);
        $this->service->assignRole($user, Role::query()->findOrFail($request->input('role_id')), $request->user());

        return ApiResponse::success($user->refresh()->load('roles'));
    }

    public function removeRole(Request $request, User $user, Role $role): Response
    {
        $this->service->removeRole($user, $role, $request->user());

        return response()->noContent();
    }

    public function assignTeam(Request $request, User $user): JsonResponse
    {
        $request->validate(['team_id' => ['required', 'ulid', 'exists:teams,id']]);
        $this->service->assignTeam($user, Team::query()->findOrFail($request->input('team_id')), $request->user());

        return ApiResponse::success($user->refresh()->load('teams'));
    }

    public function removeTeam(Request $request, User $user, Team $team): Response
    {
        $this->service->removeTeam($user, $team, $request->user());

        return response()->noContent();
    }

    public function resetTwoFactor(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success($this->service->resetTwoFactor($user, $request->user()));
    }

    public function resetLoginLock(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success($this->service->resetLoginLock($user, $request->user()));
    }

    public function resendInvitation(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success($this->service->resendWelcomeMail($user, $request->user()));
    }

    public function audit(User $user): JsonResponse
    {
        return ApiResponse::paginated(
            AuditLog::query()->where('target_id', $user->id)->latest('created_at')->paginate(50),
            fn (AuditLog $log): array => MobileApiPayload::auditLog($log),
        );
    }
}
