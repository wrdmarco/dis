<?php

namespace App\Http\Controllers;

use App\Events\AssetChanged;
use App\Http\Requests\Assets\AssignAssetRequest;
use App\Http\Requests\Assets\StoreAssetRequest;
use App\Http\Requests\Assets\UpdateAssetRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Models\AssetAssignment;
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
                    ->with(['droneType', 'activeAssignment.user'])
                    ->orderBy('asset_tag')
                    ->limit(100)
                    ->get()
                    ->map(fn (Asset $asset): array => MobileApiPayload::asset($asset))
                    ->values(),
            );
        }

        return ApiResponse::paginated(
            Asset::query()->with(['droneType', 'activeAssignment.user'])->orderBy('asset_tag')->paginate((int) $request->integer('per_page', 25)),
            fn (Asset $asset): array => MobileApiPayload::asset($asset),
        );
    }

    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::success(
            Asset::query()
                ->whereHas('assignments', fn ($assignments) => $assignments
                    ->where('user_id', $request->user()?->id)
                    ->whereNull('released_at'))
                ->orderBy('asset_tag')
                ->get()
                ->map(fn (Asset $asset): array => MobileApiPayload::asset($asset))
                ->values(),
        );
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::asset($this->service->create($request->validated(), $request->user())), 201);
    }

    public function storeMine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_tag' => ['nullable', 'string', 'max:80', 'unique:assets,asset_tag'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:drone,battery,sensor,vehicle,support_equipment'],
            'drone_type_id' => ['nullable', 'required_if:type,drone', 'ulid', 'exists:drone_types,id'],
            'has_spotlight' => ['sometimes', 'boolean'],
            'has_speaker' => ['sometimes', 'boolean'],
            'status' => ['required', 'in:ready,maintenance,unavailable'],
            'serial_number' => ['nullable', 'string', 'max:160', 'unique:assets,serial_number'],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        return ApiResponse::success(MobileApiPayload::asset($this->service->createForUser($data, $request->user())), 201);
    }

    public function show(Asset $asset): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::asset($asset->load(['droneType', 'activeAssignment.user'])));
    }

    public function update(UpdateAssetRequest $request, Asset $asset): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::asset($this->service->update($asset, $request->validated(), $request->user())));
    }

    public function destroy(Request $request, Asset $asset): JsonResponse
    {
        $this->service->delete($asset, $request->user());

        return ApiResponse::success(null);
    }

    public function assign(AssignAssetRequest $request, Asset $asset): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::assetAssignment($this->service->assign($asset, $request->validated(), $request->user())), 201);
    }

    public function release(Request $request, Asset $asset): JsonResponse
    {
        $asset->assignments()->whereNull('released_at')->update(['released_at' => now()]);
        $asset->update(['status' => 'ready']);
        AssetChanged::dispatch($asset->refresh(), 'released');

        return ApiResponse::success(MobileApiPayload::asset($asset->refresh()->load(['droneType', 'activeAssignment.user'])));
    }

    public function history(Asset $asset): JsonResponse
    {
        return ApiResponse::success(
            $asset->assignments()
                ->with(['asset.droneType', 'user'])
                ->latest('assigned_at')
                ->get()
                ->map(fn (AssetAssignment $assignment): array => MobileApiPayload::assetAssignment($assignment))
                ->values(),
        );
    }

    public function updateMine(Request $request, Asset $asset): JsonResponse
    {
        abort_unless($asset->assignments()
            ->where('user_id', $request->user()?->id)
            ->whereNull('released_at')
            ->exists(), 403);

        $data = $request->validate([
            'status' => ['required', 'in:ready,maintenance,unavailable'],
            'has_spotlight' => ['sometimes', 'boolean'],
            'has_speaker' => ['sometimes', 'boolean'],
            'maintenance_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success(MobileApiPayload::asset($this->service->update($asset, $data, $request->user())));
    }

    public function destroyMine(Request $request, Asset $asset): JsonResponse
    {
        abort_unless($asset->assignments()
            ->where('user_id', $request->user()?->id)
            ->whereNull('released_at')
            ->exists(), 403);

        $this->service->delete($asset, $request->user());

        return ApiResponse::success(null);
    }
}
