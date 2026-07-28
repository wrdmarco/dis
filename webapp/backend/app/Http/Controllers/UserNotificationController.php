<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserNotificationResource;
use App\Http\Responses\ApiResponse;
use App\Services\UserNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserNotificationController extends Controller
{
    public function __construct(private readonly UserNotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $inbox = $this->service->inbox($request->user());

        return ApiResponse::success([
            'notifications' => $inbox['notifications']
                ->map(fn ($notification): array => (new UserNotificationResource($notification))->resolve($request))
                ->values()
                ->all(),
            'unread_count' => $inbox['unread_count'],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $notification = $this->service->markRead($notification, $request->user());

        return ApiResponse::success(
            (new UserNotificationResource($notification))->resolve($request),
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'marked_read' => $this->service->markAllRead($request->user()),
        ]);
    }
}
