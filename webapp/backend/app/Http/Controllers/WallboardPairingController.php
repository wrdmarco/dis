<?php

namespace App\Http\Controllers;

use App\Http\Requests\Wallboards\StartWallboardPairingRequest;
use App\Http\Responses\ApiResponse;
use App\Services\WallboardPairingService;
use App\Services\WallboardSessionService;
use App\Services\WebSessionService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WallboardPairingController extends Controller
{
    public function __construct(
        private readonly WallboardPairingService $pairings,
        private readonly WallboardSessionService $sessions,
        private readonly WebSessionService $webSessions,
    ) {}

    public function start(StartWallboardPairingRequest $request): JsonResponse
    {
        $this->webSessions->assertStatefulWebRequest($request);
        $result = $this->pairings->start($request->validated('device_name'), $request);

        $response = ApiResponse::success($result['data']);
        $response->headers->setCookie($this->pairings->cookie(
            $result['credential'],
            $result['pairing_request'],
        ));

        return $response;
    }

    public function status(Request $request): JsonResponse
    {
        $this->webSessions->assertStatefulWebRequest($request);

        try {
            $result = $this->pairings->status($request);
        } catch (AuthenticationException) {
            $response = ApiResponse::error(
                'wallboard_pairing_unauthenticated',
                'Wallboard pairing is invalid or expired.',
                401,
            );
            $response->headers->setCookie($this->pairings->clearCookie());

            return $response;
        }

        $response = ApiResponse::success($result['data']);
        if (isset($result['session'], $result['credential'])) {
            $response->headers->setCookie($this->sessions->cookie(
                $result['credential'],
                $result['session'],
            ));
            $response->headers->setCookie($this->pairings->clearCookie());
        }

        return $response;
    }
}
