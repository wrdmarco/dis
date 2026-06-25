<?php

namespace App\Http\Controllers;

use App\Events\AssetChanged;
use App\Http\Requests\Assets\AssignAssetRequest;
use App\Http\Requests\Assets\StoreAssetRequest;
use App\Http\Requests\Assets\UpdateAssetRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Services\AssetService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssetController extends Controller
{
    public function __construct(private readonly AssetService $service) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->has('per_page')) {
            return ApiResponse::success(
                Asset::query()
                    ->orderBy('asset_tag')
                    ->limit(100)
                    ->get()
                    ->map(fn (Asset $asset): array => MobileApiPayload::asset($asset))
                    ->values(),
            );
        }

        return ApiResponse::paginated(
            Asset::query()->orderBy('asset_tag')->paginate((int) $request->integer('per_page', 25)),
            fn (Asset $asset): array => MobileApiPayload::asset($asset),
        );
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->create($request->validated(), $request->user()), 201);
    }

    public function show(Asset $asset): JsonResponse
    {
        return ApiResponse::success($asset->load('assignments'));
    }

    public function update(UpdateAssetRequest $request, Asset $asset): JsonResponse
    {
        return ApiResponse::success($this->service->update($asset, $request->validated(), $request->user()));
    }

    public function assign(AssignAssetRequest $request, Asset $asset): JsonResponse
    {
        return ApiResponse::success($this->service->assign($asset, $request->validated(), $request->user()), 201);
    }

    public function release(Request $request, Asset $asset): JsonResponse
    {
        $asset->assignments()->whereNull('released_at')->update(['released_at' => now()]);
        $asset->update(['status' => 'ready']);
        AssetChanged::dispatch($asset->refresh(), 'released');

        return ApiResponse::success($asset->load('assignments'));
    }

    public function history(Asset $asset): JsonResponse
    {
        return ApiResponse::success($asset->assignments()->latest('assigned_at')->get());
    }
}
