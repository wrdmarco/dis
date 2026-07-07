<?php

namespace App\Http\Controllers;

use App\Http\Requests\Devices\RegisterFcmTokenRequest;
use App\Http\Responses\ApiResponse;
use App\Models\FcmToken;
use App\Services\DeviceService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $service) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $request->user()
                ?->fcmTokens()
                ->latest()
                ->get()
                ->map(fn (FcmToken $token): array => MobileApiPayload::fcmToken($token))
                ->values() ?? [],
        );
    }

    public function register(RegisterFcmTokenRequest $request): Response
    {
        $this->service->registerFcmToken($request->user(), $request->validated());

        return response()->noContent();
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:180'],
            'client_type' => ['nullable', 'in:operator,admin'],
            'device_type' => ['nullable', 'in:phone,tablet'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:80'],
        ]);

        return ApiResponse::success(MobileApiPayload::fcmToken($this->service->heartbeat($request->user(), $data)));
    }

    public function revoke(Request $request, FcmToken $token): Response
    {
        $this->service->revokeFcmToken($request->user(), $token);

        return response()->noContent();
    }
}
