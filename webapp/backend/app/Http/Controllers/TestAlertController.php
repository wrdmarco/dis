<?php

namespace App\Http\Controllers;

use App\Http\Requests\TestAlerts\SendTestAlertRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DispatchRequest;
use App\Models\User;
use App\Services\DeploymentRequestService;
use App\Services\TestAlertService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TestAlertController extends Controller
{
    public function __construct(
        private readonly TestAlertService $service,
        private readonly DeploymentRequestService $deploymentRequestService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->dispatchPayload($this->service->latestFor($request->user()), $request->user()));
    }

    public function send(SendTestAlertRequest $request): JsonResponse
    {
        $result = $this->service->send($request->user(), $request->scope());

        return ApiResponse::success(
            $this->dispatchPayload($result['dispatch'], $request->user()),
            201,
            $result['summary'],
        );
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

    /**
     * @return array<string, mixed>|null
     */
    private function dispatchPayload(?DispatchRequest $dispatch, User $actor): ?array
    {
        if ($dispatch === null) {
            return null;
        }

        return MobileApiPayload::dispatch(
            $dispatch->loadMissing(['deployment.deploymentRequest.workflowRevision', 'targetTeam', 'recipients.user']),
            $actor,
            $this->deploymentRequestService,
        );
    }
}
