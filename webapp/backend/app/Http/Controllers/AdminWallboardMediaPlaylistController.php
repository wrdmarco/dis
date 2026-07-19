<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\DeleteWallboardMediaPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardMediaPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardMediaPlaylistRequest;
use App\Http\Resources\WallboardMediaPlaylistResource;
use App\Http\Responses\ApiResponse;
use App\Models\WallboardMediaPlaylist;
use App\Services\WallboardMediaPlaylistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminWallboardMediaPlaylistController extends Controller
{
    public function __construct(private readonly WallboardMediaPlaylistService $playlists) {}

    public function index(Request $request): JsonResponse
    {
        $resources = $this->playlists->all()
            ->map(fn (WallboardMediaPlaylist $playlist): array => (new WallboardMediaPlaylistResource($playlist))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success($resources);
    }

    public function store(StoreWallboardMediaPlaylistRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $playlist = $this->playlists->create($request->validated(), $actor, $request);

        return ApiResponse::success((new WallboardMediaPlaylistResource($playlist))->resolve($request), 201);
    }

    public function show(Request $request, WallboardMediaPlaylist $mediaPlaylist): JsonResponse
    {
        return ApiResponse::success(
            (new WallboardMediaPlaylistResource($this->playlists->show($mediaPlaylist)))->resolve($request),
        );
    }

    public function update(
        UpdateWallboardMediaPlaylistRequest $request,
        WallboardMediaPlaylist $mediaPlaylist,
    ): JsonResponse {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $updated = $this->playlists->update($mediaPlaylist, $request->validated(), $actor, $request);

        return ApiResponse::success((new WallboardMediaPlaylistResource($updated))->resolve($request));
    }

    public function destroy(
        DeleteWallboardMediaPlaylistRequest $request,
        WallboardMediaPlaylist $mediaPlaylist,
    ): Response {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $this->playlists->delete(
            $mediaPlaylist,
            (int) $request->validated('expected_version'),
            $actor,
            $request,
        );

        return response()->noContent();
    }
}
