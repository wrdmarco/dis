<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\MobilePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminStoreReviewController extends Controller
{
    public function __construct(private readonly MobilePairingService $pairingService) {}

    public function status(): JsonResponse
    {
        return ApiResponse::success($this->pairingService->storeReviewStatus());
    }

    public function createAndroidPairing(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        return ApiResponse::success($this->pairingService->createStoreReviewAndroid($user, $request), 201);
    }
}
