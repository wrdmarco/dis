<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\WebLoginApprovalService;
use App\Services\WebSessionService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebLoginApprovalController extends Controller
{
    public function __construct(
        private readonly WebLoginApprovalService $approvals,
        private readonly WebSessionService $webSessions,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $this->pendingLoginUser($request);
        $payload = $this->approvals->browserStatus($request, $user);
        $response = ApiResponse::success($payload);

        if ($payload['status'] === 'denied') {
            $this->webSessions->invalidate($request);
        }

        return $response;
    }

    public function complete(Request $request): JsonResponse
    {
        $authenticatedUser = $request->user();
        if ($authenticatedUser instanceof User
            && $this->webSessions->isStatefulWebRequest($request)) {
            return $this->authenticatedResponse($authenticatedUser);
        }

        $user = $this->approvals->consume($request, $this->pendingLoginUser($request));
        $this->webSessions->authenticate($request, $user);

        return $this->authenticatedResponse($user);
    }

    public function resend(Request $request): JsonResponse
    {
        return ApiResponse::success($this->approvals->resend(
            $request,
            $this->pendingLoginUser($request),
        ));
    }

    private function pendingLoginUser(Request $request): User
    {
        $user = $this->webSessions->pendingUser($request, [WebSessionService::PURPOSE_LOGIN_CHALLENGE]);
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function authenticatedResponse(User $user): JsonResponse
    {
        return ApiResponse::success([
            'authenticated' => true,
            'user' => MobileApiPayload::user($user->load(['roles.permissions', 'teams'])),
        ]);
    }
}
