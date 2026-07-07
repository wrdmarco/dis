<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\SendManualPushRequest;
use App\Http\Responses\ApiResponse;
use App\Models\FcmToken;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\PushNotificationService;
use App\Support\ApiDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminPushController extends Controller
{
    public function __construct(private readonly PushNotificationService $pushNotifications) {}

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'teams' => Team::query()
                ->with('alertTeams')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type'])
                ->map(fn (Team $team): array => [
                    'id' => $team->id,
                    'code' => $team->code,
                    'name' => $team->name,
                    'type' => $team->type,
                    'alert_teams' => $team->alertTeams->map(fn (Team $alertTeam): array => [
                        'id' => $alertTeam->id,
                        'code' => $alertTeam->code,
                        'name' => $alertTeam->name,
                        'type' => $alertTeam->type,
                    ])->values(),
                ])->values(),
            'roles' => Role::query()
                ->orderBy('display_name')
                ->get(['id', 'name', 'display_name', 'description', 'can_use_operator_app', 'can_use_admin_app'])
                ->values(),
            'users' => User::query()
                ->where('account_status', 'active')
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'email', 'account_status', 'push_enabled', 'two_factor_enabled'])
                ->values(),
        ]);
    }

    public function tokens(Request $request): JsonResponse
    {
        $tokens = FcmToken::query()
            ->with(['user.roles', 'user.teams'])
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->when($request->string('search')->toString() !== '', function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('device_id', 'like', "%{$search}%")
                        ->orWhere('platform', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($users) => $users
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->latest('last_seen_at')
            ->paginate((int) $request->integer('per_page', 50))
            ->through(fn (FcmToken $token): array => $this->tokenPayload($token));

        return ApiResponse::paginated($tokens);
    }

    public function revoke(Request $request, FcmToken $token): Response
    {
        $this->pushNotifications->revokeToken($token, $request->user());

        return response()->noContent();
    }

    public function activate(Request $request, FcmToken $token): Response
    {
        $this->pushNotifications->activateToken($token, $request->user());

        return response()->noContent();
    }

    public function send(SendManualPushRequest $request): JsonResponse
    {
        $result = $this->pushNotifications->sendManual(
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success($result, 202);
    }

    private function tokenPreview(string $token): string
    {
        return strlen($token) <= 18 ? str_repeat('*', strlen($token)) : substr($token, 0, 6).'...'.substr($token, -8);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(FcmToken $token): array
    {
        return [
            'id' => $token->id,
            'user_id' => $token->user_id,
            'device_id' => $token->device_id,
            'device_type' => $token->device_type,
            'device_name' => $token->device_name,
            'device_manufacturer' => $token->device_manufacturer,
            'device_model' => $token->device_model,
            'android_version' => $token->android_version,
            'sdk_version' => $token->sdk_version,
            'platform' => $token->platform,
            'client_type' => $token->client_type,
            'app_version' => $token->app_version,
            'is_active' => (bool) $token->is_active,
            'is_online' => (bool) $token->is_online,
            'last_seen_at' => ApiDateTime::dateTime($token->last_seen_at),
            'revoked_at' => ApiDateTime::dateTime($token->revoked_at),
            'token_preview' => $this->tokenPreview($token->token),
            'token_hash' => $token->token_hash ?? hash('sha256', $token->token),
            'user' => $token->user,
        ];
    }
}
