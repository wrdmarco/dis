<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\WebLoginApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileLoginApprovalController extends Controller
{
    public function __construct(private readonly WebLoginApprovalService $approvals) {}

    public function show(Request $request, string $approval): JsonResponse
    {
        return ApiResponse::success($this->approvals->showForApp($request, $approval));
    }

    public function approve(Request $request, string $approval): JsonResponse
    {
        return ApiResponse::success($this->approvals->decide($request, $approval, true));
    }

    public function deny(Request $request, string $approval): JsonResponse
    {
        return ApiResponse::success($this->approvals->decide($request, $approval, false));
    }
}
