<?php

namespace App\Http\Controllers;

use App\Http\Responses\WallboardMediaResponse;
use App\Models\Wallboard;
use App\Models\WallboardMediaAsset;
use App\Services\WallboardMediaDeliveryService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WallboardMediaController extends Controller
{
    public function __construct(private readonly WallboardMediaDeliveryService $delivery) {}

    public function content(
        Request $request,
        WallboardMediaAsset $asset,
    ): Response|StreamedResponse {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);
        $content = $this->delivery->forWallboard($wallboard, $asset);
        abort_if($content === null, 404);

        return WallboardMediaResponse::make($request, $content, 31_536_000);
    }
}
