<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\PasswordPolicy;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;

final class PasswordController extends Controller
{
    public function __construct(
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AuditService $auditService,
    ) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc']]);
        Password::sendResetLink($data);
        $this->auditService->record('auth.password_reset_requested', 'password_reset', null, [
            'email_hash' => hash('sha256', mb_strtolower((string) $data['email'])),
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
                'push_enabled' => false,
            ])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->fcmTokens()->where('is_active', true)->update([
                'is_active' => false,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
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
