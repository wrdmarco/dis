<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\SendManualPushRequest;
use App\Http\Responses\ApiResponse;
use App\Models\FcmToken;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminPushController extends Controller
{
    public function __construct(private readonly PushNotificationService $pushNotifications) {}

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
            ->through(fn (FcmToken $token): array => [
                'id' => $token->id,
                'user_id' => $token->user_id,
                'device_id' => $token->device_id,
                'platform' => $token->platform,
                'app_version' => $token->app_version,
                'is_active' => $token->is_active,
                'last_seen_at' => $token->last_seen_at,
                'revoked_at' => $token->revoked_at,
                'token_preview' => $this->tokenPreview($token->token),
                'token_hash' => hash('sha256', $token->token),
                'user' => $token->user,
            ]);

        return ApiResponse::paginated($tokens);
    }

    public function revoke(Request $request, FcmToken $token): JsonResponse
    {
        $this->pushNotifications->revokeToken($token, $request->user());

        return ApiResponse::success($token->refresh()->load('user'));
    }

    public function activate(Request $request, FcmToken $token): JsonResponse
    {
        $this->pushNotifications->activateToken($token, $request->user());

        return ApiResponse::success($token->refresh()->load('user'));
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
}
