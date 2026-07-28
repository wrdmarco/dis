<?php

namespace App\Http\Controllers;

use App\Exceptions\ProductRequestConflictException;
use App\Http\Requests\ProductRequests\ChangeProductRequestStatusRequest;
use App\Http\Requests\ProductRequests\IndexProductRequestsRequest;
use App\Http\Requests\ProductRequests\ShowProductRequestRequest;
use App\Http\Requests\ProductRequests\StoreProductRequestRequest;
use App\Http\Requests\ProductRequests\UpdateProductRequestRequest;
use App\Http\Resources\ProductRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProductRequest;
use App\Services\ProductRequestService;
use Illuminate\Http\JsonResponse;

final class ProductRequestController extends Controller
{
    public function __construct(private readonly ProductRequestService $service) {}

    public function index(IndexProductRequestsRequest $request): JsonResponse
    {
        $paginator = $this->service->search(
            $request->statuses(),
            $request->types(),
            $request->onlyMine(),
            $request->searchTerm(),
            $request->perPage(),
            $request->user(),
        );

        return ApiResponse::paginated(
            $paginator,
            fn (ProductRequest $productRequest): array => (new ProductRequestResource($productRequest))
                ->resolve($request),
        );
    }

    public function store(StoreProductRequestRequest $request): JsonResponse
    {
        $productRequest = $this->service->create($request->validated(), $request->user());

        return ApiResponse::success(
            (new ProductRequestResource($productRequest))->resolve($request),
            201,
        );
    }

    public function show(
        ShowProductRequestRequest $request,
        ProductRequest $productRequest,
    ): JsonResponse {
        $productRequest = $this->service->show($productRequest, $request->user());

        return ApiResponse::success(
            (new ProductRequestResource($productRequest))->resolve($request),
        );
    }

    public function update(
        UpdateProductRequestRequest $request,
        ProductRequest $productRequest,
    ): JsonResponse {
        try {
            $productRequest = $this->service->updateContent(
                $productRequest,
                $request->validated(),
                $request->user(),
            );
        } catch (ProductRequestConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success(
            (new ProductRequestResource($productRequest))->resolve($request),
        );
    }

    public function changeStatus(
        ChangeProductRequestStatusRequest $request,
        ProductRequest $productRequest,
    ): JsonResponse {
        try {
            $productRequest = $this->service->changeStatus(
                $productRequest,
                $request->validated(),
                $request->user(),
            );
        } catch (ProductRequestConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success(
            (new ProductRequestResource($productRequest))->resolve($request),
        );
    }

    private function conflict(ProductRequestConflictException $exception): JsonResponse
    {
        return ApiResponse::error(
            $exception->errorCode,
            $exception->getMessage(),
            409,
            ['current' => $exception->current],
        );
    }
}
