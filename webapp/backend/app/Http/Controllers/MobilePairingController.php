<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\MobilePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobilePairingController extends Controller
{
    public function __construct(private readonly MobilePairingService $pairingService) {}

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_type' => ['required', 'string', 'in:operator_android,operator_ios,admin_android'],
        ]);

        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        $clientType = (string) $data['client_type'];
        if (! $this->pairingService->canUseClient($user, $clientType)) {
            return ApiResponse::error('app_access_denied', 'Deze gebruiker heeft geen toegang tot deze mobiele app.', 403);
        }

        return ApiResponse::success($this->pairingService->create($user, $clientType, $request), 201);
    }

    public function consume(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'client_type' => ['required', 'string', 'in:operator_android,operator_ios,admin_android'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        return ApiResponse::success($this->pairingService->consume(
            code: (string) $data['code'],
            clientType: (string) $data['client_type'],
            deviceName: (string) ($data['device_name'] ?? 'DIS mobiele app'),
            request: $request,
        ));
    }
}
