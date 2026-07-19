<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Wallboard;
use App\Services\WallboardNewsService;
use App\Services\WallboardStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WallboardController extends Controller
{
    public function __construct(
        private readonly WallboardStateService $state,
        private readonly WallboardNewsService $news,
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

    public function newsImage(Request $request, string $image): Response
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        $result = $this->news->image($image);
        abort_if($result === null, 404);

        return response($result['body'], 200, [
            'Content-Type' => $result['content_type'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
