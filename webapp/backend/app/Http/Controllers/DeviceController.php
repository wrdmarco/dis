<?php

namespace App\Http\Controllers;

use App\Http\Requests\Devices\RegisterFcmTokenRequest;
use App\Http\Responses\ApiResponse;
use App\Models\FcmToken;
use App\Models\PersonalAccessToken;
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
                ->where('is_active', true)
                ->latest()
                ->get()
                ->map(fn (FcmToken $token): array => MobileApiPayload::fcmToken($token))
                ->values() ?? [],
        );
    }

    public function register(RegisterFcmTokenRequest $request): Response
    {
        $this->service->registerFcmToken($request->user(), $request->validated(), $this->currentPersonalAccessToken($request));

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
            'device_manufacturer' => ['nullable', 'string', 'max:80'],
            'device_model' => ['nullable', 'string', 'max:120'],
            'android_version' => ['nullable', 'string', 'max:80'],
            'sdk_version' => ['nullable', 'string', 'max:40'],
        ]);

        return ApiResponse::success(MobileApiPayload::fcmToken($this->service->heartbeat($request->user(), $data, $this->currentPersonalAccessToken($request))));
    }

    public function revoke(Request $request, string $token): Response
    {
        $fcmToken = $request->user()
            ->fcmTokens()
            ->whereKey($token)
            ->firstOrFail();

        $this->service->revokeFcmToken($request->user(), $fcmToken);

        return response()->noContent();
    }

    private function currentPersonalAccessToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $abilities = is_array($token->abilities ?? null) ? $token->abilities : [];

        return array_intersect($abilities, ['client:operator', 'client:admin']) !== [] ? $token : null;
    }
}
