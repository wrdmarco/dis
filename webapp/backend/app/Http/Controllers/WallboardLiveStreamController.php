<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\RevealWallboardLiveStreamKeyRequest;
use App\Http\Requests\Admin\RotateWallboardLiveStreamKeyRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Wallboard;
use App\Services\WallboardLiveStreamDeliveryService;
use App\Services\WallboardLiveStreamKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WallboardLiveStreamController extends Controller
{
    public function __construct(
        private readonly WallboardLiveStreamDeliveryService $stream,
        private readonly WallboardLiveStreamKeyService $keys,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $wallboard = $this->wallboard($request);
        $status = $this->stream->statusForWallboard($wallboard);
        abort_if($status === null, 404);

        return ApiResponse::success($status)
            ->header('Cache-Control', 'private, no-store')
            ->header('Vary', 'Cookie');
    }

    public function manifest(Request $request): Response
    {
        $manifest = $this->stream->manifestForWallboard($this->wallboard($request));
        abort_if($manifest === null, 404);

        return $this->manifestResponse($manifest);
    }

    public function segment(Request $request, string $segment): Response
    {
        $content = $this->stream->segmentForWallboard($this->wallboard($request), $segment);
        abort_if($content === null, 404);

        return $this->segmentResponse($content['x_accel_redirect']);
    }

    public function adminStatus(): JsonResponse
    {
        return ApiResponse::success($this->stream->statusForAdmin())
            ->header('Cache-Control', 'private, no-store')
            ->header('Vary', 'Cookie');
    }

    public function adminManifest(): Response
    {
        $manifest = $this->stream->manifestForAdmin();
        abort_if($manifest === null, 404);

        return $this->manifestResponse($manifest);
    }

    public function adminSegment(string $segment): Response
    {
        $content = $this->stream->segmentForAdmin($segment);
        abort_if($content === null, 404);

        return $this->segmentResponse($content['x_accel_redirect']);
    }

    public function revealStreamKey(RevealWallboardLiveStreamKeyRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $key = $this->keys->reveal($actor, $request);
        if ($key === null) {
            return $this->sensitiveJson(ApiResponse::error(
                'wallboard_live_stream_key_unavailable',
                'De Stream Key kan niet veilig uit de beheerde configuratie worden gelezen.',
                409,
            ));
        }

        return $this->sensitiveJson(ApiResponse::success($key));
    }

    public function rotateStreamKey(RotateWallboardLiveStreamKeyRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);
        $rotation = $this->keys->rotate($actor, $request);
        if ($rotation['outcome'] === 'succeeded') {
            unset($rotation['outcome'], $rotation['request_id']);

            return $this->sensitiveJson(ApiResponse::success($rotation));
        }

        $requestId = $rotation['request_id'];
        $details = is_string($requestId) ? ['request_id' => $requestId] : [];
        if ($rotation['outcome'] === 'key_changed') {
            return $this->sensitiveJson(ApiResponse::error(
                'wallboard_live_stream_key_changed',
                'De Stream Key is intussen door een andere beheerder gewijzigd. Haal de actuele key opnieuw op.',
                409,
                $details,
            ));
        }

        return $this->sensitiveJson(ApiResponse::error(
            'wallboard_live_stream_key_rotation_failed',
            'De Stream Key is niet aantoonbaar gewijzigd. De huidige key blijft leidend; probeer het opnieuw.',
            503,
            $details,
        ));
    }

    private function wallboard(Request $request): Wallboard
    {
        $wallboard = $request->attributes->get('wallboard');
        abort_unless($wallboard instanceof Wallboard, 401);

        return $wallboard;
    }

    private function manifestResponse(string $manifest): Response
    {
        return response($manifest, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'Vary' => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function segmentResponse(string $internalPath): Response
    {
        return response('', 200, [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'Vary' => 'Cookie',
            'X-Accel-Redirect' => $internalPath,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function sensitiveJson(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Vary', 'Cookie')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
