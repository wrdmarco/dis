<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

final class PasswordController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc,dns']]);
        Password::sendResetLink($data);

        return ApiResponse::success(['status' => 'password_reset_link_sent']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc,dns'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(14)->mixedCase()->numbers()->symbols()->uncompromised()],
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

