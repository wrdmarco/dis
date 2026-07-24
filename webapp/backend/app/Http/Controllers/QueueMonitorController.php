<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\ListQueueWorkRequest;
use App\Http\Responses\ApiResponse;
use App\Services\QueueMonitorService;
use Illuminate\Http\JsonResponse;

final class QueueMonitorController extends Controller
{
    public function __construct(private readonly QueueMonitorService $queues) {}

    public function index(ListQueueWorkRequest $request): JsonResponse
    {
        $snapshot = $this->queues->snapshot($request->filters());

        return ApiResponse::success(
            $snapshot['data'],
            200,
            $snapshot['meta'],
        )->header('Cache-Control', 'no-store, private');
    }
}
