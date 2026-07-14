<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UpdateStoreReviewAccountRequest;
use App\Http\Responses\ApiResponse;
use App\Services\StoreReviewAccountService;
use Illuminate\Http\JsonResponse;

final class AdminStoreReviewController extends Controller
{
    public function __construct(private readonly StoreReviewAccountService $accountService) {}

    public function status(): JsonResponse
    {
        return ApiResponse::success($this->accountService->status());
    }

    public function updateAccount(UpdateStoreReviewAccountRequest $request, string $platform): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        return ApiResponse::success($this->accountService->configure(
            $platform,
            (bool) $request->validated('enabled'),
            $request->validated('password'),
            $user,
            $request,
        ));
    }
}
