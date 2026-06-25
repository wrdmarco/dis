<?php

namespace App\Http\Controllers;

use App\Http\Requests\Devices\RegisterFcmTokenRequest;
use App\Http\Responses\ApiResponse;
use App\Models\FcmToken;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $service) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($request->user()?->fcmTokens()->latest()->get());
    }

    public function register(RegisterFcmTokenRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->registerFcmToken($request->user(), $request->validated()), 201);
    }

    public function revoke(Request $request, FcmToken $token): JsonResponse
    {
        $this->service->revokeFcmToken($request->user(), $token);

        return ApiResponse::success(null, 204);
    }
}

