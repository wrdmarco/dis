<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\ApproveWallboardPairingRequest;
use App\Http\Requests\Admin\SetWallboardDisplayRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Wallboard;
use App\Services\WallboardPairingService;
use App\Services\WallboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminWallboardController extends Controller
{
    public function __construct(
        private readonly WallboardService $wallboards,
        private readonly WallboardPairingService $pairings,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->wallboards->all());
    }

    public function store(StoreWallboardRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $wallboard = $this->wallboards->create($request->validated(), $actor, $request);

        return ApiResponse::success($this->wallboards->show($wallboard), 201);
    }

    public function show(Wallboard $wallboard): JsonResponse
    {
        return ApiResponse::success($this->wallboards->show($wallboard));
    }

    public function update(UpdateWallboardRequest $request, Wallboard $wallboard): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $updated = $this->wallboards->update($wallboard, $request->validated(), $actor, $request);

        return ApiResponse::success($this->wallboards->show($updated));
    }

    public function destroy(Request $request, Wallboard $wallboard): Response
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $this->wallboards->delete($wallboard, $actor, $request);

        return response()->noContent();
    }

    public function pair(ApproveWallboardPairingRequest $request, Wallboard $wallboard): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);

        return ApiResponse::success($this->pairings->approve(
            $wallboard,
            (string) $request->validated('code'),
            $actor,
            $request,
        ));
    }

    public function revokeSessions(Request $request, Wallboard $wallboard): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);

        return ApiResponse::success($this->wallboards->revokeSessions($wallboard, $actor, $request));
    }

    public function setDisplay(SetWallboardDisplayRequest $request, Wallboard $wallboard): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);

        return ApiResponse::success($this->wallboards->setDisplay(
            wallboard: $wallboard,
            pageId: $request->validated('page_id'),
            expectedControlVersion: (int) $request->validated('expected_control_version'),
            actor: $actor,
            request: $request,
        ));
    }
}
