<?php

namespace App\Http\Controllers;

use App\Http\Requests\Devices\RegisterFcmTokenRequest;
use App\Http\Responses\ApiResponse;
use App\Models\FcmToken;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $service) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($request->user()?->fcmTokens()->latest()->get());
    }

    public function register(RegisterFcmTokenRequest $request): Response
    {
        $this->service->registerFcmToken($request->user(), $request->validated());

        return response()->noContent();
    }

    public function revoke(Request $request, FcmToken $token): JsonResponse
    {
        $this->service->revokeFcmToken($request->user(), $token);

        return ApiResponse::success(null, 204);
    }
}
