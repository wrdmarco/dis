<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Http\Responses\WallboardContentResponse;
use App\Models\Wallboard;
use App\Services\WallboardNewsService;
use App\Services\WallboardStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class WallboardController extends Controller
{
    public function __construct(
        private readonly WallboardStateService $state,
        private readonly WallboardNewsService $newsImages,
    ) {}

    public function state(Request $request): JsonResponse
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        return ApiResponse::success($this->state->state($wallboard));
    }

    public function control(Request $request): JsonResponse
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        return ApiResponse::success($this->state->control($wallboard));
    }

    public function clearCache(Request $request): Response
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        return response()->noContent()->header('Clear-Site-Data', '"cache"');
    }

    public function live(Request $request): JsonResponse
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        return ApiResponse::success($this->state->live($wallboard));
    }

    public function staticContent(Request $request): JsonResponse|SymfonyResponse
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        return WallboardContentResponse::make($request, $this->state->staticContent($wallboard));
    }

    public function news(Request $request): JsonResponse|SymfonyResponse
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        return WallboardContentResponse::make($request, $this->state->news($wallboard));
    }

    public function ticker(Request $request): JsonResponse|SymfonyResponse
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        return WallboardContentResponse::make($request, $this->state->ticker($wallboard));
    }

    public function newsImage(Request $request, string $image): Response
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        $result = $this->newsImages->image($image);
        abort_if($result === null, 404);

        return response($result['body'], 200, [
            'Content-Type' => $result['content_type'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
