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

    public function schedule(): JsonResponse
    {
        return ApiResponse::success($this->service->schedule());
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'time' => ['required', 'date_format:H:i'],
            'message' => ['required', 'string', 'min:3', 'max:240'],
        ]);

        return ApiResponse::success($this->service->updateSchedule($data, $request->user()?->id));
    }
}
