<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\DeleteWallboardMediaAssetRequest;
use App\Http\Requests\Admin\IndexWallboardMediaAssetRequest;
use App\Http\Requests\Admin\StoreWallboardMediaAssetRequest;
use App\Http\Requests\Admin\UpdateWallboardMediaAssetRequest;
use App\Http\Resources\WallboardMediaAssetResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\WallboardMediaResponse;
use App\Models\WallboardMediaAsset;
use App\Services\WallboardMediaAssetService;
use App\Services\WallboardMediaDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminWallboardMediaAssetController extends Controller
{
    public function __construct(
        private readonly WallboardMediaAssetService $assets,
        private readonly WallboardMediaDeliveryService $delivery,
    ) {}

    public function index(IndexWallboardMediaAssetRequest $request): JsonResponse
    {
        $folderId = $request->boolean('unfiled')
            ? ''
            : ($request->validated('folder_id') === null ? null : (string) $request->validated('folder_id'));
        $paginator = $this->assets->paginate(
            $folderId,
            $request->validated('search'),
            (int) ($request->validated('per_page') ?? 25),
        );

        return ApiResponse::paginated(
            $paginator,
            fn (WallboardMediaAsset $asset): array => (new WallboardMediaAssetResource($asset))->resolve($request),
        );
    }

    public function store(StoreWallboardMediaAssetRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $asset = $this->assets->upload($request->validated(), $actor, $request);

        return ApiResponse::success((new WallboardMediaAssetResource($asset))->resolve($request), 201);
    }

    public function show(Request $request, WallboardMediaAsset $asset): JsonResponse
    {
        return ApiResponse::success(
            (new WallboardMediaAssetResource($this->assets->show($asset)))->resolve($request),
        );
    }

    public function update(
        UpdateWallboardMediaAssetRequest $request,
        WallboardMediaAsset $asset,
    ): JsonResponse {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $updated = $this->assets->update($asset, $request->validated(), $actor, $request);

        return ApiResponse::success((new WallboardMediaAssetResource($updated))->resolve($request));
    }

    public function destroy(
        DeleteWallboardMediaAssetRequest $request,
        WallboardMediaAsset $asset,
    ): Response {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $this->assets->delete($asset, (int) $request->validated('expected_version'), $actor, $request);

        return response()->noContent();
    }

    public function content(Request $request, WallboardMediaAsset $asset): Response|StreamedResponse
    {
        $content = $this->delivery->forAdmin($asset);
        abort_if($content === null, 404);

        return WallboardMediaResponse::make($request, $content, 3600);
    }
}
