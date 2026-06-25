<?php

namespace App\Http\Controllers;

use App\Events\DispatchChanged;
use App\Http\Requests\Dispatch\RespondDispatchRequest;
use App\Http\Requests\Dispatch\StoreDispatchRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Services\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DispatchController extends Controller
{
    public function __construct(private readonly DispatchService $service) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(DispatchRequest::query()->with(['incident', 'recipients'])->latest()->paginate((int) $request->integer('per_page', 25)));
    }

    public function store(StoreDispatchRequest $request, Incident $incident): JsonResponse
    {
        return ApiResponse::success($this->service->create($incident, $request->validated(), $request->user()), 201);
    }

    public function show(DispatchRequest $dispatch): JsonResponse
    {
        return ApiResponse::success($dispatch->load(['incident', 'recipients.user']));
    }

    public function send(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        return ApiResponse::success($this->service->markSent($dispatch, $request->user()));
    }

    public function respond(RespondDispatchRequest $request, DispatchRequest $dispatch): Response
    {
        $this->service->respond($dispatch, $request->user(), $request->validated('response'), $request->validated('note'));

        return response()->noContent();
    }

    public function cancel(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $dispatch->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        DispatchChanged::dispatch($dispatch->refresh(), 'cancelled');

        return ApiResponse::success($dispatch);
    }

    public function escalate(Request $request, DispatchRequest $dispatch): JsonResponse
    {
        $dispatch->update(['status' => 'escalated']);
        DispatchChanged::dispatch($dispatch->refresh(), 'escalated');

        return ApiResponse::success($dispatch);
    }

    public function recipients(DispatchRequest $dispatch): JsonResponse
    {
        return ApiResponse::success($dispatch->recipients()->with('user')->get());
    }
}
