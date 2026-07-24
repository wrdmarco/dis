<?php

namespace App\Http\Controllers;

use App\Exceptions\QueueActionException;
use App\Http\Requests\Admin\ListQueueWorkRequest;
use App\Http\Requests\Admin\ManageQueueWorkRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\QueueActionService;
use App\Services\QueueMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class QueueMonitorController extends Controller
{
    public function __construct(
        private readonly QueueMonitorService $queues,
        private readonly QueueActionService $actions,
    ) {}

    public function index(ListQueueWorkRequest $request): JsonResponse
    {
        $snapshot = $this->queues->snapshot($request->filters());

        return ApiResponse::success(
            $snapshot['data'],
            200,
            $snapshot['meta'],
        )->header('Cache-Control', 'no-store, private');
    }

    public function start(ManageQueueWorkRequest $request, string $queue, string $workItem): JsonResponse
    {
        return $this->executeAction($request, $queue, $workItem, 'start');
    }

    public function retry(ManageQueueWorkRequest $request, string $queue, string $workItem): JsonResponse
    {
        return $this->executeAction($request, $queue, $workItem, 'retry');
    }

    private function executeAction(
        ManageQueueWorkRequest $request,
        string $queue,
        string $workItem,
        string $action,
    ): JsonResponse {
        if ($queue !== 'push' || ! Str::isUlid($workItem)) {
            return ApiResponse::error(
                'queue_item_not_found',
                'De wachtrijtaak bestaat niet meer.',
                404,
            );
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        try {
            $result = $this->actions->execute(
                $queue,
                $workItem,
                $action,
                $actor,
                $request,
            );
        } catch (QueueActionException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return ApiResponse::success($result, 202)
            ->header('Cache-Control', 'no-store, private');
    }
}
