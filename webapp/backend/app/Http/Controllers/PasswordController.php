<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\AuditService;
use App\Services\PasswordPolicy;
use App\Services\PasswordRecoveryService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

final class PasswordController extends Controller
{
    public function __construct(
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordRecoveryService $passwordRecoveryService,
        private readonly AuditService $auditService,
        private readonly UserService $userService,
    ) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc']]);
        $this->passwordRecoveryService->queue((string) $data['email']);
        $this->auditService->record('auth.password_reset_requested', 'password_reset', null, [
            'email_hash' => hash('sha256', mb_strtolower(trim((string) $data['email']))),
        ], null, $request);

        return ApiResponse::success(['status' => 'password_reset_link_sent']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', $this->passwordPolicy->rule()],
        ]);

        $status = Password::reset($data, function ($user, string $password) use ($request): void {
            $user->forceFill([
                'password' => $password,
                'failed_login_attempts' => 0,
                'login_locked_until' => null,
            ])->save();
            $this->userService->revokeAuthenticationState(
                $user,
                $user,
                'auth.password_reset_sessions_revoked',
            );
            $this->auditService->record('auth.password_reset_completed', $user, $user, [
                'sessions_revoked' => true,
            ], null, $request);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('validation_failed', 'Unable to reset password with the supplied token.', 422);
        }

        return ApiResponse::success(['status' => 'password_reset']);
    }
}
