<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Http\Responses\OperationalRadarResponse;
use App\Http\Responses\WallboardContentResponse;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Services\WallboardNewsService;
use App\Services\WallboardPlaylistResolver;
use App\Services\WallboardStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WallboardController extends Controller
{
    public function __construct(
        private readonly WallboardStateService $state,
        private readonly WallboardNewsService $newsImages,
        private readonly WallboardPlaylistResolver $playlists,
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
        abort_if(
            $this->playlists->resolveRuntime($wallboard, false)['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO,
            404,
        );

        $result = $this->newsImages->image($image);
        abort_if($result === null, 404);

        return response($result['body'], 200, [
            'Content-Type' => $result['content_type'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function weatherRadarAtlas(
        Request $request,
        string $kind,
        string $snapshot,
    ): SymfonyResponse|StreamedResponse {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);
        $content = $this->state->weatherRadarAtlas($wallboard, $kind, $snapshot);
        abort_if($content === null, 404);

        return OperationalRadarResponse::make($request, $content);
    }
}
