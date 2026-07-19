<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\DeleteWallboardMediaFolderRequest;
use App\Http\Requests\Admin\StoreWallboardMediaFolderRequest;
use App\Http\Requests\Admin\UpdateWallboardMediaFolderRequest;
use App\Http\Resources\WallboardMediaFolderResource;
use App\Http\Responses\ApiResponse;
use App\Models\WallboardMediaFolder;
use App\Services\WallboardMediaFolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminWallboardMediaFolderController extends Controller
{
    public function __construct(private readonly WallboardMediaFolderService $folders) {}

    public function index(Request $request): JsonResponse
    {
        $resources = $this->folders->all()
            ->map(fn (WallboardMediaFolder $folder): array => (new WallboardMediaFolderResource($folder))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success($resources);
    }

    public function store(StoreWallboardMediaFolderRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $folder = $this->folders->create($request->validated(), $actor, $request);

        return ApiResponse::success((new WallboardMediaFolderResource($folder))->resolve($request), 201);
    }

    public function update(
        UpdateWallboardMediaFolderRequest $request,
        WallboardMediaFolder $folder,
    ): JsonResponse {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $updated = $this->folders->update($folder, $request->validated(), $actor, $request);

        return ApiResponse::success((new WallboardMediaFolderResource($updated))->resolve($request));
    }

    public function destroy(
        DeleteWallboardMediaFolderRequest $request,
        WallboardMediaFolder $folder,
    ): Response {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $this->folders->delete($folder, (int) $request->validated('expected_version'), $actor, $request);

        return response()->noContent();
    }
}
