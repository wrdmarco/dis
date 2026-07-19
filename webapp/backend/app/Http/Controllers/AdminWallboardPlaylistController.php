<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\AssignWallboardPlaylistRequest;
use App\Http\Requests\Admin\DeleteWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Http\Resources\WallboardPlaylistAssignmentResource;
use App\Http\Resources\WallboardPlaylistResource;
use App\Http\Responses\ApiResponse;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Services\WallboardPlaylistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminWallboardPlaylistController extends Controller
{
    public function __construct(private readonly WallboardPlaylistService $playlists) {}

    public function index(Request $request): JsonResponse
    {
        $resources = $this->playlists->all()
            ->map(fn (WallboardPlaylist $playlist): array => (new WallboardPlaylistResource($playlist))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success($resources);
    }

    public function store(StoreWallboardPlaylistRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $playlist = $this->playlists->create($request->validated(), $actor, $request);

        return ApiResponse::success((new WallboardPlaylistResource($playlist))->resolve($request), 201);
    }

    public function show(Request $request, WallboardPlaylist $wallboardPlaylist): JsonResponse
    {
        $playlist = $this->playlists->show($wallboardPlaylist);

        return ApiResponse::success((new WallboardPlaylistResource($playlist))->resolve($request));
    }

    public function update(
        UpdateWallboardPlaylistRequest $request,
        WallboardPlaylist $wallboardPlaylist,
    ): JsonResponse {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $playlist = $this->playlists->update($wallboardPlaylist, $request->validated(), $actor, $request);

        return ApiResponse::success((new WallboardPlaylistResource($playlist))->resolve($request));
    }

    public function destroy(
        DeleteWallboardPlaylistRequest $request,
        WallboardPlaylist $wallboardPlaylist,
    ): Response {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $this->playlists->delete(
            $wallboardPlaylist,
            (int) $request->validated('expected_version'),
            $actor,
            $request,
        );

        return response()->noContent();
    }

    public function assign(AssignWallboardPlaylistRequest $request, Wallboard $wallboard): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $updated = $this->playlists->assign(
            $wallboard,
            (string) $request->validated('playlist_id'),
            (int) $request->validated('expected_config_version'),
            $actor,
            $request,
        );

        return ApiResponse::success((new WallboardPlaylistAssignmentResource($updated))->resolve($request));
    }
}
