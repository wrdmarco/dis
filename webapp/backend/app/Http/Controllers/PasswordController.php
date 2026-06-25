<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

final class PasswordController extends Controller
{
    public function __construct(private readonly PasswordPolicy $passwordPolicy) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc']]);
        Password::sendResetLink($data);

        return ApiResponse::success(['status' => 'password_reset_link_sent']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', $this->passwordPolicy->rule()],
        ]);

        $status = Password::reset($data, function ($user, string $password): void {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('validation_failed', 'Unable to reset password with the supplied token.', 422);
        }

        return ApiResponse::success(['status' => 'password_reset']);
    }
}
