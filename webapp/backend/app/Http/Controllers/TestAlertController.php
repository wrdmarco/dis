<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\TestAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TestAlertController extends Controller
{
    public function __construct(private readonly TestAlertService $service) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->latestFor($request->user()));
    }

    public function send(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->send($request->user()), 201);
    }
}
