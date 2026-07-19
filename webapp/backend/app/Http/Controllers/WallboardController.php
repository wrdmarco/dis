<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Wallboard;
use App\Services\WallboardStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WallboardController extends Controller
{
    public function __construct(private readonly WallboardStateService $state) {}

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
}
